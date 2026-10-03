<?php

/*
 * The master's supervision of its workers, tested from the outside: faults injected through
 * the fixture's routes (a worker exiting, running out of memory, leaking, stuck in a busy
 * loop or a blocking call, throwing), shutdown, rolling reloads and recycling, also under
 * load. swerve_start() logs to a file at INFO level with --grace=3 --watchdog=3; each test's
 * processes are killed by process group afterwards (see Pest.php).
 */

use phasync\Psr\Response;

/**
 * A fresh directory for the fixture's SWERVE_TEST_DIR, with version.php returning $version.
 */
function test_dir(?string $version = 'v1'): string
{
    $dir = temp_path(dir: true);
    if (null !== $version) {
        write_version($dir, $version);
    }

    return $dir;
}

function write_version(string $dir, string $version): void
{
    file_put_contents("$dir/version.php.tmp", "<?php return '$version';\n");
    rename("$dir/version.php.tmp", "$dir/version.php");
}

/**
 * Send a GET and leave it in flight.
 *
 * @return resource
 */
function send_get(string $addr, string $path, bool $keepAlive = false)
{
    $conn = native_connect($addr);
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\n" . ($keepAlive ? '' : "Connection: close\r\n") . "\r\n");

    return $conn;
}

/**
 * The position of each pattern in the log, in the order given; fails when one is missing or
 * out of order.
 */
function expect_log_order(string $log, array $regexes): void
{
    $text   = file_get_contents($log);
    $offset = 0;
    foreach ($regexes as $regex) {
        expect(preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE, $offset))->toBe(1, "$regex after offset $offset in:\n$text");
        $offset = $m[0][1] + strlen($m[0][0]);
    }
}

/**
 * Wait until 10 answers in a row are $version: a replaced worker serves on until it notices
 * the master's SIGTERM, a tick after the reload completed.
 */
function wait_version(string $addr, string $version, float $timeout = 3, bool $fastcgi = false): void
{
    $deadline = microtime(true) + $timeout;
    for ($same = 0; $same < 10;) {
        expect(microtime(true))->toBeLessThan($deadline, "Not all answers were $version within $timeout s");
        $answer = $fastcgi ? fcgi_get($addr, '/version')['body'] ?? null : probe($addr, '/version');
        $same   = $answer === $version ? $same + 1 : 0;
    }
}

function is_alive(int $pid): bool
{
    set_error_handler(static fn (): bool => true); // it may end while it is read
    try {
        $stat = file_get_contents("/proc/$pid/stat");
    } finally {
        restore_error_handler();
    }

    return false !== $stat && !str_contains($stat, ') Z ');
}

/*
 * Crash and faults
 */

test('a worker that exits is logged with its exit code and replaced, while the other serves', function () {
    [$process, $addr, $log] = swerve_start();
    $before = worker_pids($addr, 2);
    expect(probe($addr, '/exit?code=7'))->toBeNull();

    $ok = 0;
    for ($i = 0; $i < 10; ++$i) {
        $ok += (int) ('Hello' === probe($addr, '/hello', 0.5));
    }
    expect($ok)->toBeGreaterThanOrEqual(1);
    log_wait($log, '/Worker \d+ \(slot \d\) died: exit 7, up /');
    expect(array_diff(worker_pids($addr, 2, 2), $before))->not->toBeEmpty();
    native_stop($process);
});

test('an application exception answers 500 and does not end the worker', function () {
    [$process, $addr, $log] = swerve_start();
    $before = worker_pids($addr, 2);
    for ($i = 0; $i < 20; ++$i) {
        $conn = send_get($addr, '/throw');
        expect(native_read_response($conn)['status'])->toBe(500);
        fclose($conn);
    }
    $after = worker_pids($addr, 2);
    sort($before);
    sort($after);
    expect($after)->toBe($before);
    expect(log_count($log, '/died/'))->toBe(0);
    native_stop($process);
});

test('a worker hitting memory_limit dies with PHP\'s message in the log and is replaced', function () {
    [$process, $addr, $log] = swerve_start(php: ['-d', 'memory_limit=32M']);
    $before = worker_pids($addr, 2);
    expect(native_read_response(send_get($addr, '/oom')))->toBeNull();

    $deadline = microtime(true) + 1;
    while ('Hello' !== probe($addr, '/hello', 0.5)) {
        expect(microtime(true))->toBeLessThan($deadline);
    }
    log_wait($log, '/exit 255 \(PHP fatal error/');
    log_wait($log, '/Allowed memory size/');
    expect(array_diff(worker_pids($addr, 2, 2), $before))->not->toBeEmpty();
    native_stop($process);
});

test('the watchdog kills a worker stuck in a busy loop, while the other serves', function (string $path) {
    [$process, $addr, $log] = swerve_start(['--watchdog=2']);
    worker_pids($addr, 2);
    $stuck = send_get($addr, $path);
    $start = microtime(true);

    $ok = 0;
    for ($i = 0; $i < 20; ++$i) {
        $ok += (int) ('Hello' === probe($addr, '/hello', 0.1));
    }
    expect($ok)->toBeGreaterThanOrEqual(1);
    $match = log_wait($log, '/Worker (\d+) \(slot \d\) sent no heartbeat for [\d.]+ s: killing it/', 3.5 - (microtime(true) - $start));
    log_wait($log, '/died: signal 9 \(SIGKILL\).*killed: watchdog/', 1);
    expect(is_alive((int) $match[0][1]))->toBeFalse();
    expect(native_closed($stuck))->toBeTrue();

    for ($i = 0; $i < 10; ++$i) {
        expect(probe($addr, '/pid'))->not->toBeNull();
    }
    native_stop($process);
})->with(['busy loop' => '/spin']);

test('an idle worker, or one waiting on a slow request, is not stuck', function () {
    [$process, $addr, $log] = swerve_start(['--watchdog=2'], workers: 1);
    $pid  = probe($addr, '/pid');
    $conn = send_get($addr, '/sleep?ms=3000&id=a');
    expect(native_read_response($conn)['body'])->toBe('slept a');
    usleep(1_000_000);

    expect(probe($addr, '/pid'))->toBe($pid);
    expect(log_count($log, '/heartbeat/'))->toBe(0);
    native_stop($process);
});

test('a blocking call in a request shorter than the watchdog timeout is not interrupted', function (string $watchdog) {
    [$process, $addr] = swerve_start(["--watchdog=$watchdog"], workers: 1);
    $conn             = send_get($addr, '/usleep?ms=4500');
    stream_set_timeout($conn, 8);
    expect((float) native_read_response($conn)['body'])->toBeGreaterThanOrEqual(4.45);
    native_stop($process);
})->with(['watchdog off' => '0', 'watchdog 10 s' => '10']);

test('--watch on a large tree leaves the watchdog working and the master mostly idle', function () {
    $dir = test_dir();
    file_put_contents("$dir/app.php", "<?php return require '" . __DIR__ . "/Fixtures/app.php';\n");
    // 384k entries, hard links made by the kernel, so that one scan takes over a second
    exec('cd ' . escapeshellarg($dir) . ' && mkdir -p node_modules/p && cd node_modules/p && seq 1 750 | sed s/$/.js/ | xargs touch'
        . ' && cd .. && for i in 1 2 3 4 5 6 7 8 9; do mkdir ../t && cp -al . ../t/x && mv ../t c$i; done', $out, $code);
    expect($code)->toBe(0);
    [$process, $addr, $log, $pid] = swerve_start(['--watch', '--watchdog=2'], env: ['SWERVE_TEST_DIR' => $dir], fixture: "$dir/app.php");
    worker_pids($addr, 2);
    $spin = send_get($addr, '/spin');

    log_wait($log, '/sent no heartbeat for [\d.]+ s: killing it/', 8);
    $ticks = static fn () => array_sum(array_slice(explode(' ', substr($s = file_get_contents("/proc/$pid/stat"), strrpos($s, ')') + 2)), 11, 2));
    $before = $ticks();
    usleep(4_000_000);
    expect($ticks() - $before)->toBeLessThan(240); // of 400 in 4 s: at most one scan
    native_stop($process);
});

test('the idle master\'s CPU use grows no faster than its number of workers', function () {
    [$process, $addr, $log, $pid] = swerve_start(workers: 64, wait: false);
    $deadline = microtime(true) + 30;
    while (log_count($log, '/\) ready in /') < 64) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(100_000);
    }
    usleep(1_000_000);
    $ticks  = static fn () => array_sum(array_slice(explode(' ', substr($s = file_get_contents("/proc/$pid/stat"), strrpos($s, ')') + 2)), 11, 2));
    $before = $ticks();
    usleep(5_000_000);
    expect($ticks() - $before)->toBeLessThan(10); // 2 % of a core
    native_stop($process, 20);
});

test('a crash loop backs off exponentially and is logged loudly; a stable worker resets it', function () {
    $dir                    = test_dir(null);
    [$process, $addr, $log] = swerve_start(workers: 1, env: ['SWERVE_TEST_DIR' => $dir]);
    touch("$dir/crash");
    expect(probe($addr, '/exit'))->toBeNull();

    log_wait($log, '/crash-looping: 3 failed starts in a row/', 6);
    expect_log_order($log, ['/Restarting slot 0 in 0\.5 s/', '/Restarting slot 0 in 1 s/', '/critical +Slot 0 is crash-looping: 3 failed starts in a row \(last: exit 3/']);

    unlink("$dir/crash");
    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello', 0.2)) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
    }
    usleep(5_500_000);
    log_wait($log, '/Slot 0 recovered/', 1);
    $restarts = log_count($log, '/Restarting slot/');
    $pid      = probe($addr, '/pid');
    expect(probe($addr, '/exit'))->toBeNull();

    $deadline = microtime(true) + 1;
    while (null === ($new = probe($addr, '/pid', 0.2)) || $new === $pid) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    expect(log_count($log, '/Restarting slot/'))->toBe($restarts);
    native_stop($process);
});

test('an application that returns no handler stops the master with exit code 2', function () {
    [$process, , $log, $pid] = swerve_start(fixture: 'bad.php', wait: false);
    [$code, $seconds]        = swerve_wait($process, 3);

    expect($code)->toBe(2);
    expect(file_get_contents($log))->toContain('returned')->toContain('failed to start');
    expect(group_gone($pid))->toBeTrue();
});

test('a port taken by another program stops the master with an error', function () {
    $taken   = stream_socket_server('tcp://127.0.0.1:0');
    $addr    = stream_socket_get_name($taken, false);
    $log     = temp_path();
    $process = swerve_spawn(["--http=$addr", '--workers=2', "--log=$log", '-vv'], 'app.php');
    [$code]  = swerve_wait($process, 3);

    expect($code)->not->toBe(0);
    expect(file_get_contents($log))->toContain('failed to start');
});

test('FastCGI: an application exception answers 500, and the connection serves on', function () {
    [$process, $addr] = swerve_start(mode: 'fastcgi');
    $conn             = fcgi_connect($addr);
    stream_set_timeout($conn, 1);
    fwrite($conn, fcgi_request(1, 'GET', '/throw'));
    $response = fcgi_read_responses($conn, [1])[1];
    expect($response['status'])->toBe(500);
    expect($response['protocolStatus'])->toBe(0);

    fwrite($conn, fcgi_request(2, 'GET', '/hello'));
    expect(fcgi_read_responses($conn, [2])[2]['body'])->toBe('Hello');
    native_stop($process);
});

test('FastCGI without the cluster: an application exception answers 500', function () {
    [$worker, $addr] = fcgi_start_worker();
    try {
        $conn = fcgi_connect($addr);
        stream_set_timeout($conn, 1);
        fwrite($conn, fcgi_request(1, 'GET', '/throw'));
        expect(fcgi_read_responses($conn, [1])[1]['status'])->toBe(500);
    } finally {
        fcgi_stop_worker($worker);
    }
});

test('a worker stuck when the master dies ends itself, instead of holding the port as an orphan', function (string $path) {
    // It checks a second after the watchdog's timeout, see Worker's SIGALRM handler
    [$process, $addr, $log, $pid] = swerve_start(['--watchdog=2']);
    worker_pids($addr, 2);
    $stuck = send_get($addr, $path);
    usleep(300_000);
    posix_kill($pid, SIGKILL);
    proc_close($process);

    expect(group_gone($pid, 5))->toBeTrue();
    expect(native_closed($stuck))->toBeTrue();
    expect(file_get_contents($log))->toMatch('/Master process died while the event loop was stuck/');
})->with(['busy loop' => '/spin']);

test('one worker dying while the others start does not stop the server', function () {
    $dir = test_dir();
    file_put_contents("$dir/load-ms", '1500');
    [$process, $addr, $log, $pid] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir], wait: false);
    usleep(700_000);
    $children = array_values(array_diff(group_pids($pid), [$pid]));
    expect($children)->toHaveCount(4);
    posix_kill($children[0], SIGKILL);

    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello', 0.5)) {
        expect(proc_get_status($process)['running'])->toBeTrue(file_get_contents($log));
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
    }
    expect(file_get_contents($log))->toMatch('/died: signal 9 \(SIGKILL\)/')->not->toContain('failed to start');
    expect(worker_pids($addr, 4))->toHaveCount(4);
    native_stop($process);
});

test('a request that kills its worker again and again does not put the slots in backoff', function () {
    [$process, $addr, $log] = swerve_start();
    $ok       = $total = 0;
    $lastExit = 0.0;
    $until    = microtime(true) + 5;
    while (microtime(true) < $until) {
        if (microtime(true) - $lastExit >= 0.3) {
            $lastExit = microtime(true);
            if ($conn = @stream_socket_client("tcp://$addr", $errno, $errstr, 0.5)) {
                fwrite($conn, "GET /exit?code=9 HTTP/1.1\r\nHost: test\r\n\r\n");
                fclose($conn);
            }
        }
        ++$total;
        $ok += (int) ('Hello' === probe($addr, '/hello', 0.5));
    }
    expect($ok / $total)->toBeGreaterThan(0.8);
    expect(log_count($log, '/died: exit 9/'))->toBeGreaterThanOrEqual(8);
    expect(log_count($log, '/crash-looping|Restarting slot/'))->toBe(0);
    native_stop($process);
});

test('pausing the whole server (SIGSTOP, SIGCONT) does not make the watchdog kill the workers', function () {
    [$process, $addr, $log, $pid] = swerve_start(['--watchdog=1'], workers: 4);
    $before = worker_pids($addr, 4);
    posix_kill(-$pid, SIGSTOP);
    usleep(2_500_000);
    posix_kill(-$pid, SIGCONT);
    usleep(1_500_000);

    expect(log_count($log, '/heartbeat|died/'))->toBe(0);
    $after = worker_pids($addr, 4);
    sort($before);
    sort($after);
    expect($after)->toBe($before);
    native_stop($process);
});

test('a worker killed by SIGTERM in its shutdown after a fatal error is logged with the fatal error', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, php: ['-d', 'memory_limit=64M']);
    $worker                 = (int) probe($addr, '/pid');
    $conn                   = send_get($addr, '/oom?small=1');
    // SIGTERM over and over, as the master's drain would, so that one arrives while PHP shuts down
    while (is_alive($worker)) {
        posix_kill($worker, SIGTERM);
        usleep(1000);
    }
    $match = log_wait($log, "/Worker $worker \\(slot 0\\) died: (.*)/");
    expect($match[0][1])->toContain('PHP fatal error');
    native_stop($process);
});

test('a child the master did not start ending (a wrapper\'s background job, an orphan adopted as PID 1) does not stop the master', function () {
    $addr    = free_address();
    $log     = temp_path();
    // `job & exec swerve`, a common entrypoint: the job becomes the master's child
    $process = proc_open(
        ['setsid', 'sh', '-c', 'sleep 1 & exec "$@"', 'sh', PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http=$addr", '--workers=2', "--log=$log", '-vv', '--grace=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $deadline                   = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    usleep(1_500_000);

    expect(proc_get_status($process)['running'])->toBeTrue(file_get_contents($log));
    expect(probe($addr, '/hello'))->toBe('Hello');
    // PHP's own messages are capitalized, swerve's levels are not
    expect(file_get_contents($log))->not->toContain('Warning')->not->toContain('Error')->not->toMatch('/ (warning|error|critical) /')->not->toContain('died');
    native_stop($process);
});

test('an application that exits 0 while loading fails to start with a non-zero exit code', function () {
    $app = temp_path();
    file_put_contents($app, "<?php die('no config');\n");
    [$process, , $log, $pid] = swerve_start(fixture: $app, wait: false);
    [$code]                  = swerve_wait($process, 5);

    expect($code)->not->toBe(0);
    expect(file_get_contents($log))->toContain('failed to start');
    expect(group_gone($pid))->toBeTrue();
});

test('FastCGI: exit() in a request is logged with its exit code, without PHP errors', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, mode: 'fastcgi');
    for ($round = 1; $round <= 3; ++$round) {
        // Each on a connection of its own with FCGI_KEEP_CONN, closed by the client, as cgi-fcgi does
        foreach (['/pid', '/hello', '/pid', '/exit?code=5'] as $path) {
            $conn = fcgi_connect($addr);
            fwrite($conn, fcgi_request(1, 'GET', $path));
            '/exit?code=5' === $path ? expect(fcgi_read_record($conn))->toBeNull() : fcgi_read_responses($conn, [1]);
            fclose($conn);
        }
        $deadline = microtime(true) + 3;
        while (log_count($log, '/died: /') < $round || null === fcgi_get($addr, '/hello', 0.3)) {
            expect(microtime(true))->toBeLessThan($deadline, file_get_contents($log));
            usleep(20_000);
        }
    }
    expect(log_count($log, '/died: exit 5, /'))->toBe(3);
    expect(file_get_contents($log))->not->toContain('UNHANDLED')->not->toContain('Fatal error')->not->toContain('exit 255');
    native_stop($process);
});

test('an exception in a background coroutine of the application is logged', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    expect(probe($addr, '/bgthrow'))->toBe('ok');
    log_wait($log, '/RuntimeException: background boom/', 3);
    expect(probe($addr, '/hello'))->toBe('Hello');
    native_stop($process);
});

test('a client can\'t change what the log says with terminal markup in the request', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $target                 = '/throw?a=<!!>X<!redBG>Y<!unknown>Z<!clear>W<!>';
    $conn                   = send_get($addr, $target);
    expect(native_read_response($conn)['status'])->toBe(500);
    fclose($conn);
    log_wait($log, '/RuntimeException: boom/');
    expect(file_get_contents($log))->toContain("GET $target failed");
    native_stop($process);
});

test('a worker signalled from outside swerve drains, and is logged with the signal\'s name', function () {
    [$process, $addr, $log] = swerve_start();
    [$a, $b]                = worker_pids($addr, 2);
    posix_kill($a, SIGTERM);
    posix_kill($b, SIGUSR1);

    log_wait($log, "/Worker $a \\(slot \\d\\) exited after draining/");
    log_wait($log, "/Worker $b \\(slot \\d\\) died: signal 10 \\(SIGUSR1\\)/");
    expect(file_get_contents($log))->toMatch("/Worker $a \\(slot \\d\\) is draining on a SIGTERM the master did not send/")->not->toMatch("/Worker $a .*died/");
    expect(worker_pids($addr, 2))->not->toContain($a)->not->toContain($b);
    native_stop($process);
});

test('without -v, the log names the worker that replaces one that died', function () {
    $addr     = free_address();
    $log      = temp_path();
    $process  = swerve_spawn(["--http=$addr", '--workers=1', "--log=$log", '--grace=2'], 'app.php');
    $deadline = microtime(true) + 5;
    while (null === ($pid = probe($addr, '/pid'))) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    expect(probe($addr, '/exit'))->toBeNull();
    $deadline = microtime(true) + 3;
    while (null === ($new = probe($addr, '/pid', 0.3)) || $new === $pid) {
        expect(microtime(true))->toBeLessThan($deadline);
    }
    expect_log_order($log, ["/Worker $pid \\(slot 0\\) died/", "/Started worker $new in slot 0/"]);
    expect(file_get_contents($log))->not->toContain(' alert ');
    native_stop($process);
});

test('a background job of the application keeps no listener after its worker died, nor after shutdown', function () {
    [$process, $addr] = swerve_start(workers: 1);
    expect(probe($addr, '/spawn?s=30'))->toBe('spawned');
    $pid = probe($addr, '/pid');
    expect(probe($addr, '/exit?code=3'))->toBeNull();
    $deadline = microtime(true) + 3;
    while (null === ($new = probe($addr, '/pid', 0.3)) || $new === $pid) {
        expect(microtime(true))->toBeLessThan($deadline);
    }
    for ($i = 0; $i < 10; ++$i) {
        expect(probe($addr, '/pid'))->toBe($new);
    }
    native_stop($process);
    expect(@stream_socket_server("tcp://$addr"))->not->toBeFalse();
})->skip(!function_exists('socket_create'), 'the test uses ext-sockets');

test('FastCGI: a background job of the application keeps no listener after a reload, nor after shutdown', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, mode: 'fastcgi');
    expect(fcgi_get($addr, '/spawn?s=30')['body'])->toBe('spawned');
    swerve_signal($process, SIGHUP);
    log_wait($log, '/exited after draining/', 4);
    for ($i = 0; $i < 10; ++$i) {
        expect(fcgi_get($addr, '/hello'))->not->toBeNull();
    }
    native_stop($process);
    expect(@stream_socket_server("tcp://$addr"))->not->toBeFalse();
});

test('FastCGI: a drain that runs out of time ends the worker without a PHP fatal error', function () {
    [$process, $addr, $log] = swerve_start(['--grace=2'], workers: 1, mode: 'fastcgi');
    $conn                   = fcgi_connect($addr);
    fwrite($conn, fcgi_request(1, 'GET', '/sleep?ms=10000&id=a'));
    usleep(300_000);
    swerve_signal($process, SIGTERM);
    swerve_wait($process, 4);

    expect(file_get_contents($log))->toContain('Drain deadline reached')->not->toContain('Fatal error')->not->toContain('UNHANDLED')->not->toContain('exit 255');
});

test('an application exception is logged with its request', function () {
    [$process, $addr, $log] = swerve_start();
    probe($addr, '/throw?x=1');
    log_wait($log, '/RuntimeException: boom/');
    expect(file_get_contents($log))->toContain('GET /throw?x=1');
    native_stop($process);
});

test('FastCGI: an application exception is logged with its request', function () {
    [$process, $addr, $log] = swerve_start(mode: 'fastcgi');
    fcgi_get($addr, '/throw?x=1');
    log_wait($log, '/RuntimeException: boom/');
    expect(file_get_contents($log))->toContain('GET /throw?x=1');
    native_stop($process);
});

test('a log that can\'t be written, under an application error handler that throws, neither ends the worker nor cuts its drain short', function (string $mode) {
    // /dev/full fails every write as a full disk does
    $addr     = free_address();
    $process  = swerve_spawn(["--$mode=$addr", '--workers=1', '--grace=5', '--log=/dev/full', '-vv'], 'strict.php');
    $get      = static fn (string $path) => 'http' === $mode ? native_read_response(send_get($addr, $path)) : fcgi_get($addr, $path, 3);
    $deadline = microtime(true) + 5;
    while ('Hello' !== ('http' === $mode ? probe($addr, '/hello') : fcgi_get($addr, '/hello')['body'] ?? null)) {
        expect(proc_get_status($process)['running'])->toBeTrue();
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    $pid = $get('/pid')['body'];
    expect($get('/throw')['status'] ?? null)->toBe(500);
    expect($get('/pid')['body'])->toBe($pid);

    if ('http' === $mode) {
        $conn = send_get($addr, '/sleep?ms=1000&id=a');
    } else {
        $conn = fcgi_connect($addr);
        fwrite($conn, fcgi_request(1, 'GET', '/sleep?ms=1000&id=a'));
    }
    usleep(300_000);
    swerve_signal($process, SIGTERM);
    expect('http' === $mode ? native_read_response($conn)['body'] : fcgi_read_responses($conn, [1])[1]['body'])->toBe('slept a');
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
})->with(['http', 'fastcgi']);

test('a PHP fatal error is logged by its worker, with the requests it had in flight', function () {
    [$process, $addr, $log] = swerve_start(php: ['-d', 'memory_limit=32M']);
    expect(probe($addr, '/oom?x=1'))->toBeNull();
    $died = log_wait($log, '/Worker \d+ \(slot (\d)\) died: exit 255/');
    expect(file_get_contents($log))->toMatch('/\.\d\d ' . $died[0][1] . ' critical +PHP fatal error: Allowed memory size .*; in flight: GET \/oom\?x=1/');
    native_stop($process);
});

test('the watchdog logs the request a worker is stuck in, and where, before killing it', function () {
    [$process, $addr, $log] = swerve_start(['--watchdog=2']);
    $stuck = send_get($addr, '/spin?user=42');
    $match = log_wait($log, '/Worker \d+ \(slot (\d)\) died: signal 9 \(SIGKILL\).*killed: watchdog/', 5);
    expect(file_get_contents($log))->toMatch('/\.\d\d ' . $match[0][1] . ' warning +Silent for [\d.]+ s; in flight: GET \/spin\?user=42; at .*Fixtures\/app\.php:\d+/');
    native_stop($process);
});

test('SIGQUIT on a worker that never ticked (still loading the application) says how long since it started', function () {
    $dir = test_dir();
    file_put_contents("$dir/load-ms", '3000');
    [$process, $addr, $log, $pid] = swerve_start(['--watchdog=0'], workers: 1, env: ['SWERVE_TEST_DIR' => $dir], wait: false);
    $deadline = microtime(true) + 5;
    while ('' === ($worker = trim((string) shell_exec("pgrep -P $pid")))) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    usleep(500_000);
    posix_kill((int) $worker, SIGQUIT);
    $match = log_wait($log, '/warning +Silent for ([\d.]+) s; in flight: none/');
    expect((float) $match[0][1])->toBeLessThan(10.0);
    native_stop($process);
});

test('a worker busy with blocking requests back to back, each shorter than the watchdog timeout, is not killed', function () {
    [$process, $addr, $log] = swerve_start(['--watchdog=2'], workers: 1);
    $conns                  = [];
    for ($i = 0; $i < 6; ++$i) {
        $conns[] = $conn = send_get($addr, '/usleep?ms=800');
        stream_set_timeout($conn, 10);
    }
    foreach ($conns as $conn) {
        expect(native_read_response($conn)['status'] ?? null)->toBe(200);
    }
    expect(log_count($log, '/heartbeat|died/'))->toBe(0);
    native_stop($process);
});

test('an application exit(255) is not logged as a PHP fatal error', function () {
    [$process, $addr, $log] = swerve_start();
    expect(probe($addr, '/exit?code=255'))->toBeNull();
    $match = log_wait($log, '/died: exit 255(.*)/');
    expect($match[0][1])->toStartWith(', up ');
    native_stop($process);
});

test('FastCGI: a protocol error is logged once, with the peer, and without a PHP warning', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, mode: 'fastcgi');
    $conn                   = fcgi_connect($addr);
    fwrite($conn, fcgi_record(99, 1));
    log_wait($log, '/unknown request id 1/');
    usleep(200_000);

    expect(log_count($log, '/ProtocolErrorException/'))->toBe(1);
    expect(file_get_contents($log))->toContain('type 99 with unknown request id 1')->toContain(stream_socket_get_name($conn, false))->not->toContain('Undefined array key');
    expect(fcgi_get($addr, '/hello')['body'])->toBe('Hello');
    native_stop($process);
});

test('a worker at its connection limit says so in the log', function () {
    $addr    = free_address();
    $log     = temp_path();
    // 100 descriptors: the worker serves at most 36 connections
    $process = proc_open(
        ['setsid', 'sh', '-c', 'ulimit -n 100 && exec "$@"', 'sh', PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http=$addr", '--workers=1', "--log=$log", '--grace=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $deadline                   = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    $conns = [];
    for ($i = 0; $i < 40; ++$i) {
        $conns[] = send_get($addr, "/sleep?ms=2000&id=$i");
    }
    log_wait($log, '/warning +At the limit of 36 connections.*ulimit -n/', 3);
    native_stop($process);
});

test('a worker at its connection limit without the phasync extension does not advise raising ulimit -n, which can\'t help', function () {
    if (extension_loaded('phasync')) {
        $this->markTestSkipped('the phasync extension is loaded');
    }
    $addr    = free_address();
    $log     = temp_path();
    $process = proc_open(
        ['setsid', 'sh', '-c', 'ulimit -n 4096 && exec "$@"', 'sh', PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http=$addr", '--workers=1', "--log=$log", '--grace=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $deadline                   = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    // Upgraded connections, which are never closed to make room: one more than the limit,
    // half of PHP_FD_SETSIZE, which leaves the other half for the application's own descriptors
    $conns = [];
    for ($i = 0; $i < 513; ++$i) {
        $conns[$i] = native_connect($addr);
        fwrite($conns[$i], "GET /upgrade-echo HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: echo\r\n\r\n");
    }
    [[$line]] = log_wait($log, '/warning +At the limit of 512 connections[^\n]*/', 5);
    expect($line)->not->toContain('ulimit')->toContain('phasync extension');
    foreach ($conns as $conn) {
        fclose($conn);
    }
    native_stop($process);
});

test('a worker serves at most half of PHP_FD_SETSIZE connections without the phasync extension, the open-file limit less 64 with it', function () {
    $addr    = free_address();
    $log     = temp_path();
    $process = proc_open(
        ['setsid', 'sh', '-c', 'ulimit -n 4096 && exec "$@"', 'sh', PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--http=$addr", '--workers=1', '-v', "--log=$log", '--grace=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $max                        = extension_loaded('phasync') ? 4096 - 64 : PHP_FD_SETSIZE / 2;
    [[$line]] = log_wait($log, '/at most \d+ connections/', 5);
    expect($line)->toContain("at most $max connections");
    native_stop($process);
});

/*
 * Recycling
 */

test('a leaking worker is recycled before memory_limit, without failing a request', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, php: ['-d', 'memory_limit=64M']);
    $pid    = probe($addr, '/pid');
    $failed = 0;
    for ($i = 0; $i < 60 && ($now = probe($addr, '/pid')) === $pid; ++$i) {
        $failed += (int) (null === probe($addr, '/leak?kb=1024'));
        usleep(25_000);
    }
    expect($now)->not->toBe($pid);
    expect($failed)->toBeLessThanOrEqual(resets_allowed());
    log_wait($log, '/exited after draining \(exit 0/', 2);
    expect_log_order($log, ['/Recycling: memory [\d.]+ MiB over limit/', '/took over, draining \d+ \(recycle\)/', '/exited after draining \(exit 0/']);
    expect(log_count($log, '/exit 255/'))->toBe(0);
    native_stop($process);
});

test('leaking workers are recycled under concurrent load without failed requests', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, php: ['-d', 'memory_limit=128M']);
    $load                   = http_load($addr, '/leak?kb=256&ms=20', 4, 8);

    expect([$load['status5xx'], $load['truncated']])->toBe([0, 0]);
    expect($load['reset'])->toBeLessThanOrEqual(resets_allowed());
    expect(log_count($log, '/took over, draining/'))->toBeGreaterThanOrEqual(1);
    expect(log_count($log, '/exit 255/'))->toBe(0);
    native_stop($process);
});

test('--max-requests recycles a worker after about that many requests', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=20'], workers: 1);
    $pids                   = [];
    $failed                 = 0;
    // The replacement needs a moment to start: keep asking until it answers
    for ($i = 0, $until = microtime(true) + 5; $i < 70 || (count($pids) < 2 && microtime(true) < $until); ++$i) {
        $pid = probe($addr, '/pid');
        null === $pid ? ++$failed : $pids[$pid] = true;
        if ($i >= 70) {
            usleep(5000);
        }
    }
    expect(count($pids))->toBeGreaterThanOrEqual(2);
    expect($failed)->toBeLessThanOrEqual(resets_allowed());
    foreach (log_wait($log, '/Recycling: served (\d+) requests/') as $match) {
        expect((int) $match[1])->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(24);
    }
    native_stop($process);
});

test('memory recycling is off when memory_limit is -1', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, php: ['-d', 'memory_limit=-1']);
    $pid                    = probe($addr, '/pid');
    for ($i = 0; $i < 20; ++$i) {
        probe($addr, '/leak?kb=1024');
    }
    expect(probe($addr, '/pid'))->toBe($pid);
    expect(file_get_contents($log))->toContain('memory recycling off (memory_limit is -1');
    native_stop($process);
});

test('memory recycling that is off says why', function (string $limit, string $option, string $says, string $level) {
    [$process, , $log] = swerve_start(["--max-memory=$option"], workers: 1, php: ['-d', "memory_limit=$limit"]);
    native_stop($process);

    // Only warnings and worse name their level
    expect(file_get_contents($log))->toMatch('/\.\d\d [ \d]+ ' . ('' !== $level ? preg_quote($level, '/') . ' +' : '') . '.*' . preg_quote($says, '/') . '/')->not->toContain('pass --max-memory to enable');
})->with([
    'a percentage of no limit' => ['-1', '50%', 'memory_limit is -1, so --max-memory=50% is no limit', 'warning'],
    '0 %'                      => ['64M', '0%', 'memory recycling off', ''],
]);

test('--max-memory above 100 % is refused', function () {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' --max-memory=150% --http=' . free_address() . ' missing.php 2>&1', $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain('a percentage up to 100%, required');
});

test('--max-memory recycles even when memory_limit is -1', function () {
    [$process, $addr] = swerve_start(['--max-memory=16M'], workers: 1, php: ['-d', 'memory_limit=-1']);
    $pid              = probe($addr, '/pid');
    for ($i = 0; $i < 20 && ($now = probe($addr, '/pid')) === $pid; ++$i) {
        probe($addr, '/leak?kb=4096');
        usleep(50_000);
    }
    expect($now)->not->toBe($pid);
    native_stop($process);
});

test('the memory recycling log counts the requests served', function () {
    [$process, $addr, $log] = swerve_start(['--max-memory=16M'], workers: 1, php: ['-d', 'memory_limit=-1']);
    for ($i = 0; $i < 6; ++$i) {
        probe($addr, '/leak?kb=4096');
    }
    $match = log_wait($log, '/Recycling: memory .*, (\d+) requests\)/');
    expect((int) $match[0][1])->toBeGreaterThanOrEqual(2);
    native_stop($process);
});

test('a recycling limit below the memory the application starts with is refused loudly, without churn', function () {
    [$process, $addr, $log] = swerve_start(['--max-memory=1M'], workers: 1);
    $pid = probe($addr, '/pid');
    for ($i = 0; $i < 10; ++$i) {
        expect(probe($addr, '/pid'))->toBe($pid);
        usleep(50_000);
    }
    expect(file_get_contents($log))->toMatch('/warning .*above the recycling limit/')->not->toContain('Recycling:');
    native_stop($process);
});

test('a recycling worker that dies before its replacement is ready leaves no recycle behind', function () {
    $dir = test_dir();
    file_put_contents("$dir/load-ms", '1000');
    [$process, $addr, $log] = swerve_start(['--max-memory=12M'], workers: 1, php: ['-d', 'memory_limit=-1'], env: ['SWERVE_TEST_DIR' => $dir]);
    probe($addr, '/leak?kb=16000');
    log_wait($log, '/Started worker \d+ in slot 0 \(generation 0\), replacing/');
    expect(probe($addr, '/exit'))->toBeNull();
    $deadline = microtime(true) + 3;
    while (count($ready = log_wait($log, '/Worker (\d+) \(slot 0\) ready/')) < 2) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
    }
    $pid = $ready[1][1];
    for ($i = 0; $i < 10; ++$i) {
        expect(probe($addr, '/pid'))->toBe($pid);
        usleep(100_000);
    }
    expect(file_get_contents($log))->not->toContain('took over')->not->toContain('Restarting slot');
    native_stop($process);
});

test('a recycle whose replacement keeps failing to start backs off exponentially and is logged as a crash loop', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(['--max-requests=3'], workers: 1, env: ['SWERVE_TEST_DIR' => $dir]);
    touch("$dir/crash");
    for ($i = 0; $i < 6; ++$i) {
        expect(probe($addr, '/pid'))->not->toBeNull();
    }
    // The old worker serves on, ready for longer than a stable start takes by the fifth failure
    log_wait($log, '/critical +Recycle of slot 0 failed: .*\(5 failed starts in a row\)/', 12);
    expect_log_order($log, ['/retrying in 0\.5 s/', '/retrying in 1 s/', '/retrying in 2 s/', '/retrying in 4 s/', '/retrying in 8 s/']);
    expect(log_count($log, '/recovered/'))->toBe(0);
    expect(probe($addr, '/hello'))->toBe('Hello');
    native_stop($process);
});

test('a --max-memory at or above memory_limit is said to be no limit, and a size too large is refused', function () {
    [$process, , $log] = swerve_start(['--max-memory=2G'], workers: 1, php: ['-d', 'memory_limit=64M']);
    native_stop($process);
    expect(file_get_contents($log))->toMatch('/warning .*--max-memory=2G is not below memory_limit 64M/')->toContain('memory recycling off');

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' --max-memory=99999999999999999999 --http=' . free_address() . ' missing.php 2>&1', $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain('--max-memory is too large');
});

/*
 * Shutdown
 */

test('SIGTERM lets requests in flight finish, stops accepting, and exits', function () {
    [$process, $addr, , $pid] = swerve_start();
    $conns                    = [];
    for ($i = 0; $i < 3; ++$i) {
        $conns[] = send_get($addr, "/sleep?ms=1500&id=$i", keepAlive: true);
    }
    usleep(200_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    usleep(400_000);
    expect(probe($addr, '/hello', 0.5))->toBeNull(); // refused

    foreach ($conns as $i => $conn) {
        $response = native_read_response($conn);
        expect($response['status'])->toBe(200);
        expect($response['body'])->toBe("slept $i");
        expect($response['headers']['connection'] ?? null)->toBe('close');
    }
    [$code, $seconds] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    expect(microtime(true) - $start)->toBeLessThan(2.5);
    expect(group_gone($pid))->toBeTrue();
});

test('SIGTERM closes idle keep-alive connections at once', function () {
    [$process, $addr] = swerve_start();
    $conn             = send_get($addr, '/hello', keepAlive: true);
    expect(native_read_response($conn)['body'])->toBe('Hello');
    $start = microtime(true);
    swerve_signal($process, SIGTERM);

    expect(native_closed($conn))->toBeTrue();
    expect(microtime(true) - $start)->toBeLessThan(0.5);
    [$code] = swerve_wait($process, 1 - (microtime(true) - $start));
    expect($code)->toBe(0);
});

test('shutdown never takes longer than the grace period', function () {
    [$process, $addr, $log, $pid] = swerve_start(['--grace=1']);
    $spin                         = send_get($addr, '/spin');
    $sleep                        = send_get($addr, '/sleep?ms=10000&id=x');
    usleep(100_000);
    swerve_signal($process, SIGTERM);

    [, $seconds] = swerve_wait($process, 3);
    expect($seconds)->toBeLessThan(1.8);
    expect(file_get_contents($log))->toMatch('/Grace expired: killing|Drain deadline reached/');
    expect(group_gone($pid))->toBeTrue();
});

test('a second signal kills the workers at once', function () {
    [$process, $addr, $log] = swerve_start(['--grace=10']);
    $sleep                  = send_get($addr, '/sleep?ms=10000&id=x');
    usleep(100_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    usleep(200_000);
    swerve_signal($process, SIGINT);

    swerve_wait($process, 3);
    expect(microtime(true) - $start)->toBeLessThan(1);
    expect(file_get_contents($log))->toContain('Second signal');
});

test('Ctrl+C to the process group drains gracefully: the workers leave it to the master', function () {
    [$process, $addr, , $pid] = swerve_start();
    $conn                     = send_get($addr, '/sleep?ms=1000&id=c');
    usleep(100_000);
    posix_kill(-$pid, SIGINT);

    expect(native_read_response($conn)['body'])->toBe('slept c');
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
});

test('workers drain and exit when the master is killed', function () {
    [$process, $addr, $log, $pid] = swerve_start();
    $conn                         = send_get($addr, '/sleep?ms=500&id=k');
    usleep(100_000);
    posix_kill($pid, SIGKILL);

    expect(native_read_response($conn)['body'])->toBe('slept k');
    fclose($conn); // else the worker lingers for the client's end until its drain deadline
    expect(group_gone($pid))->toBeTrue();
    expect(file_get_contents($log))->toContain('Master process died');
    proc_close($process);
});

test('FastCGI: SIGTERM finishes multiplexed requests in flight, then closes the connection', function () {
    [$process, $addr] = swerve_start(mode: 'fastcgi');
    $conn             = fcgi_connect($addr);
    fwrite($conn, fcgi_request(1, 'GET', '/sleep?ms=1000&id=1') . fcgi_request(2, 'GET', '/sleep?ms=1000&id=2'));
    usleep(200_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);

    $responses = fcgi_read_responses($conn, [1, 2]);
    expect([$responses[1]['body'], $responses[2]['body']])->toBe(['slept 1', 'slept 2']);
    expect(fcgi_read_record($conn))->toBeNull();
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    expect(microtime(true) - $start)->toBeLessThan(2);
});

test('two signals sent back to back still kill the workers at once', function () {
    [$process, $addr, $log] = swerve_start(['--grace=10']);
    $sleep                  = send_get($addr, '/sleep?ms=3000&id=x');
    usleep(100_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    swerve_signal($process, SIGINT);

    swerve_wait($process, 4);
    expect(microtime(true) - $start)->toBeLessThan(1);
    expect(file_get_contents($log))->toContain('Second signal');
});

test('SIGTERM is acted on at once, not at the next heartbeat', function () {
    [$process, $addr] = swerve_start(workers: 4);
    $conns            = [];
    $pids             = [];
    for ($i = 0; count($pids) < 4 || $i < 16; ++$i) {
        expect($i)->toBeLessThan(200);
        $conn   = send_get($addr, '/pid', keepAlive: true);
        $pid    = native_read_response($conn)['body'];
        $conns[] = $conn;
        $pids[$pid] = true;
    }
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    foreach ($conns as $conn) {
        expect(native_closed($conn))->toBeTrue();
    }
    expect(microtime(true) - $start)->toBeLessThan(0.1);
    swerve_wait($process, 2);
});

test('drain answers a request that reached an idle keep-alive connection before the drain', function () {
    $handler = new class implements Psr\Http\Server\RequestHandlerInterface {
        public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            return new Response(200, [], 'Hello');
        }
    };
    [$first, $rest] = phasync::run(function () use ($handler) {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);
        $connection = new Swerve\Http\HttpConnection($server, '127.0.0.1:1', new Swerve\Dispatcher($handler, new Psr\Log\NullLogger()), new Psr\Log\NullLogger());
        phasync::go($connection->serve(...));
        fwrite($client, "GET /1 HTTP/1.1\r\nHost: test\r\n\r\n");
        phasync::sleep(0.05);
        $first = fread($client, 65536);
        // The next request is in the kernel's buffer, not yet read, when the drain starts
        fwrite($client, "GET /2 HTTP/1.1\r\nHost: test\r\n\r\n");
        $connection->drain();
        $rest = '';
        do {
            $chunk = fread(phasync::readable($client, 2), 65536);
            $rest .= $chunk;
        } while ('' !== $chunk);

        return [$first, $rest];
    });
    expect($first)->toContain('Hello')->not->toContain('Connection: close');
    expect($rest)->toStartWith('HTTP/1.1 200')->toContain("\r\nConnection: close\r\n")->toEndWith('Hello');
});

test('FastCGI: requests sent before the drain started are answered', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, mode: 'fastcgi');
    $worker                 = (int) fcgi_get($addr, '/pid')['body'];
    $conns                  = [fcgi_connect($addr), fcgi_connect($addr)];
    usleep(200_000); // accepted
    posix_kill($worker, SIGSTOP);
    foreach ($conns as $conn) {
        fwrite($conn, fcgi_request(1, 'GET', '/hello', keepConn: false));
    }
    swerve_signal($process, SIGTERM);
    usleep(200_000);
    posix_kill($worker, SIGCONT);

    foreach ($conns as $conn) {
        expect(fcgi_read_responses($conn, [1])[1]['body'])->toBe('Hello');
    }
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    expect(file_get_contents($log))->not->toContain('Buffer has been ended')->not->toContain('ended while draining');
});

test('FastCGI: a request sent on an open connection after SIGTERM is answered, and the drain ends at once', function () {
    [$process, $addr, $log] = swerve_start(['--grace=6'], workers: 1, mode: 'fastcgi');
    $conn                   = fcgi_connect($addr);
    fwrite($conn, fcgi_request(1, 'GET', '/sleep?ms=1000&id=1'));
    usleep(200_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    usleep(400_000);
    fwrite($conn, fcgi_request(2, 'GET', '/sleep?ms=100&id=2'));

    $responses = fcgi_read_responses($conn, [1, 2]);
    expect([$responses[1]['body'], $responses[2]['body']])->toBe(['slept 1', 'slept 2']);
    expect(fcgi_read_record($conn))->toBeNull();
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    expect(microtime(true) - $start)->toBeLessThan(2);
    expect(file_get_contents($log))->not->toContain('Drain deadline');
});

test('a drain that runs out of time ends the worker without a PHP fatal error', function () {
    [$process, $addr, $log] = swerve_start(['--grace=2'], workers: 1);
    $conn                   = send_get($addr, '/stream?n=1000&ms=100');
    usleep(300_000);
    swerve_signal($process, SIGTERM);
    swerve_wait($process, 4);

    expect(file_get_contents($log))->toContain('Drain deadline reached')->not->toContain('Fatal error')->not->toContain('exit 255');
});

test('a log reader that stops reading stops neither the server nor its shutdown', function () {
    $fifo = temp_path();
    unlink($fifo);
    posix_mkfifo($fifo, 0600);
    $reader = fopen($fifo, 'r+'); // never read: the pipe stays full
    stream_set_blocking($reader, false);
    while (@fwrite($reader, str_repeat('x', 4096))) {
    }
    $addr    = free_address();
    $process = swerve_spawn(["--http=$addr", '--workers=2', '-vv', '--grace=1'], 'app.php', out: [1 => ['file', $fifo, 'w'], 2 => ['file', $fifo, 'w']]);
    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello', 0.5)) {
        expect(microtime(true))->toBeLessThan($deadline);
    }
    for ($i = 0; $i < 20; ++$i) {
        probe($addr, '/throw'); // more to log
    }
    expect(probe($addr, '/hello'))->toBe('Hello');

    swerve_signal($process, SIGTERM);
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    fclose($reader);
});

test('a drain does not cut short a blocking call in a request in flight', function (string $mode, ?int $signal, array $args) {
    [$process, $addr, $log] = swerve_start($args, workers: 1, env: ['SWERVE_TEST_DIR' => test_dir()], mode: $mode);
    if ('http' === $mode) {
        if (null === $signal) {
            expect(probe($addr, '/hello'))->toBe('Hello'); // the second request: a recycle
        }
        $conn = send_get($addr, '/usleep?ms=2000');
        stream_set_timeout($conn, 5);
    } else {
        $conn = fcgi_connect($addr);
        fwrite($conn, fcgi_request(1, 'GET', '/usleep?ms=2000'));
    }
    usleep(300_000);
    if (null !== $signal) {
        swerve_signal($process, $signal);
    }
    $body = 'http' === $mode ? native_read_response($conn)['body'] : fcgi_read_responses($conn, [1])[1]['body'];
    expect((float) $body)->toBeGreaterThanOrEqual(1.99);
    log_wait($log, '/Draining/', 2);
    native_stop($process);
})->with([
    'reload'            => ['http', SIGHUP, []],
    'recycle'           => ['http', null, ['--max-requests=2']],
    'shutdown'          => ['http', SIGTERM, []],
    'FastCGI: reload'   => ['fastcgi', SIGHUP, []],
    'FastCGI: shutdown' => ['fastcgi', SIGTERM, []],
]);

test('SIGTERM to the whole process group, as systemd sends it, is not taken for a signal from outside', function () {
    $servers = [];
    for ($i = 0; $i < 4; ++$i) {
        $servers[] = swerve_start(['--grace=3'], workers: 16);
    }
    foreach ($servers as [$process, $addr]) {
        http_load($addr, '/hello', 0.3, 4);
    }
    foreach ($servers as [$process, $addr, $log, $pid]) {
        posix_kill(-$pid, SIGTERM);
    }
    foreach ($servers as [$process, $addr, $log]) {
        [$code] = swerve_wait($process, 5);
        expect($code)->toBe(0);
        expect(file_get_contents($log))->not->toContain('did not send');
    }
});

test('SIGUSR1 reopens the log, SIGUSR2 reloads and SIGQUIT stops gracefully, as with php-fpm', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    rename($log, "$log.1");
    $GLOBALS['swerve_temp'][] = "$log.1";
    swerve_signal($process, SIGUSR1);
    log_wait($log, '/Reopened the log file \(SIGUSR1\)/', 2);
    probe($addr, '/throw?after=usr1');
    log_wait($log, '/after=usr1/', 2);

    write_version($dir, 'v2');
    swerve_signal($process, SIGUSR2);
    log_wait($log, '/Reload complete/', 3);
    wait_version($addr, 'v2', 2);

    $conn = send_get($addr, '/sleep?ms=500&id=q');
    usleep(100_000);
    swerve_signal($process, SIGQUIT);
    expect(native_read_response($conn)['body'])->toBe('slept q');
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
});

test('a worker still loading the application when the master dies ends itself', function () {
    $dir = test_dir();
    file_put_contents("$dir/load-ms", '600000'); // an application stuck while it loads
    [$process, , $log, $pid] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir], wait: false);
    usleep(1_000_000);
    expect(group_pids($pid))->toHaveCount(3);
    posix_kill($pid, SIGKILL);
    proc_close($process);

    // A second after the master would have killed it as not ready
    expect(group_gone($pid, Swerve\Util\Cluster::READY_TIMEOUT + 3))->toBeTrue();
    expect(file_get_contents($log))->toContain('Master process died while the application was loading');
});

/*
 * Reload
 */

test('SIGHUP replaces the workers one at a time with ones running the new code', function () {
    $dir                          = test_dir();
    [$process, $addr, $log, $pid] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    $old                          = worker_pids($addr, 2);
    expect(probe($addr, '/version'))->toBe('v1');
    write_version($dir, 'v2');
    swerve_signal($process, SIGHUP);

    $deadline = microtime(true) + 3;
    do {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
        $versions = [];
        for ($i = 0; $i < 10; ++$i) {
            $versions[] = probe($addr, '/version');
        }
    } while (['v2'] !== array_values(array_unique($versions)) || is_alive($old[0]) || is_alive($old[1]));
    expect(proc_get_status($process)['running'])->toBeTrue();
    expect_log_order($log, [
        '/Started worker (\d+) in slot 0 \(generation 1\), replacing/', '/Worker \d+ \(slot 0\) ready/', '/Slot 0: worker \d+ took over, draining \d+ \(reload\)/',
        '/Started worker (\d+) in slot 1 \(generation 1\), replacing/', '/Worker \d+ \(slot 1\) ready/', '/Slot 1: worker \d+ took over, draining \d+ \(reload\)/',
        '/Reload complete/',
    ]);
    native_stop($process);
});

test('a reload whose new code fails to load is held up; the old workers serve on', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    file_put_contents("$dir/version.php", "<?php return 'v2' +;\n");
    swerve_signal($process, SIGHUP);

    log_wait($log, '/Reload held up: new worker for slot 0 exit 2 before becoming ready/');
    $until = microtime(true) + 2;
    while (microtime(true) < $until) {
        expect(probe($addr, '/version'))->toBe('v1');
    }
    write_version($dir, 'v3');
    swerve_signal($process, SIGHUP);
    log_wait($log, '/Reload complete/', 3);
    wait_version($addr, 'v3', 1);
    native_stop($process);
});

test('a SIGHUP during a reload replaces every worker again', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    file_put_contents("$dir/load-ms", '300'); // the second SIGHUP comes while slot 0 starts
    write_version($dir, 'v2');
    swerve_signal($process, SIGHUP);
    usleep(50_000);
    swerve_signal($process, SIGHUP);

    $failed   = 0;
    $deadline = microtime(true) + 4;
    while (!log_count($log, '/Reload complete/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        $failed += (int) (null === probe($addr, '/hello'));
    }
    expect($failed)->toBeLessThanOrEqual(resets_allowed());
    expect(log_count($log, '/Reload requested/'))->toBe(2);
    expect(log_count($log, '/Reload complete/'))->toBe(1);
    $serving = [];
    foreach (log_wait($log, '/Started worker (\d+) in slot (\d) \(generation (\d)\)/') as $m) {
        $serving[$m[2]] = $m[3];
    }
    expect($serving)->toBe(['0' => '2', '1' => '2']);
    wait_version($addr, 'v2', 1);
    native_stop($process);
});

test('SIGTERM during a reload also stops the starting worker', function () {
    $dir                          = test_dir();
    [$process, $addr, $log, $pid] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    file_put_contents("$dir/load-ms", '500');
    swerve_signal($process, SIGHUP);
    usleep(100_000);
    swerve_signal($process, SIGTERM);

    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    expect(group_gone($pid))->toBeTrue();
});

test('--watch reloads once when files change', function () {
    $dir = test_dir();
    file_put_contents("$dir/app.php", "<?php return require '" . __DIR__ . "/Fixtures/app.php';\n");
    [$process, $addr, $log] = swerve_start(['--watch'], env: ['SWERVE_TEST_DIR' => $dir], fixture: "$dir/app.php");
    expect(probe($addr, '/version'))->toBe('v1');
    for ($i = 1; $i <= 3; ++$i) {
        // A different size each time, as mtimes have whole seconds only
        file_put_contents("$dir/version.php", "<?php return 'v2';" . str_repeat(' ', $i) . "\n");
        usleep(70_000);
    }

    $deadline = microtime(true) + 4;
    while ('v2' !== probe($addr, '/version') || log_count($log, '/Reload complete/') < 1) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
    }
    expect(log_count($log, '/Change detected in .*version\.php; reloading/'))->toBe(1);
    native_stop($process);
});

test('a worker started by a reload that dies later is restarted as a crash, not logged as a failed reload', function () {
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => test_dir()]);
    swerve_signal($process, SIGHUP);
    log_wait($log, '/Reload complete/');
    expect(probe($addr, '/exit?code=9'))->toBeNull();

    log_wait($log, '/died: exit 9/');
    worker_pids($addr, 2);
    expect(file_get_contents($log))->not->toContain('Reload aborted');
    native_stop($process);
});

test('a new worker dying during a reload, after it took over, does not stop the reload', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    file_put_contents("$dir/load-ms", '300');
    write_version($dir, 'v2');
    swerve_signal($process, SIGHUP);
    $match = log_wait($log, '/Slot 0: worker (\d+) took over/');
    posix_kill((int) $match[0][1], SIGKILL);

    log_wait($log, '/Reload complete/', 5);
    wait_version($addr, 'v2', 3);
    expect(file_get_contents($log))->not->toContain('Reload aborted');
    native_stop($process);
});

test('--watch survives dangling symlinks and unreadable directories', function () {
    $dir = test_dir();
    file_put_contents("$dir/app.php", "<?php return require '" . __DIR__ . "/Fixtures/app.php';\n");
    mkdir("$dir/private", 0);
    try {
        [$process, $addr, $log] = swerve_start(['--watch'], env: ['SWERVE_TEST_DIR' => $dir], fixture: "$dir/app.php");
        symlink("$dir/nowhere", "$dir/.#app.php"); // an Emacs lock file
        symlink("$dir/nowhere", "$dir/gone.php");
        usleep(2_500_000);
        expect(probe($addr, '/version'))->toBe('v1');

        write_version($dir, 'v2');
        log_wait($log, '/Reload complete/', 4);
        wait_version($addr, 'v2', 1);
        native_stop($process);
    } finally {
        chmod("$dir/private", 0700);
    }
});

test('a reload after a symlink swap runs the new release', function () {
    $dir = temp_path(dir: true);
    foreach (['rel1' => 'v1', 'rel2' => 'v2'] as $release => $version) {
        mkdir("$dir/$release");
        write_version("$dir/$release", $version);
        file_put_contents("$dir/$release/app.php", "<?php putenv('SWERVE_TEST_DIR=' . __DIR__);\nreturn require '" . __DIR__ . "/Fixtures/app.php';\n");
    }
    symlink("$dir/rel1", "$dir/current");
    [$process, $addr, $log] = swerve_start(fixture: "$dir/current/app.php");
    expect(probe($addr, '/version'))->toBe('v1');

    symlink("$dir/rel2", "$dir/current.tmp");
    rename("$dir/current.tmp", "$dir/current");
    swerve_signal($process, SIGHUP);
    log_wait($log, '/Reload complete/');
    wait_version($addr, 'v2', 2);
    native_stop($process);
});

test('SIGHUP reopens the log file, for log rotation', function () {
    [$process, $addr, $log] = swerve_start();
    rename($log, "$log.1");
    $GLOBALS['swerve_temp'][] = "$log.1";
    swerve_signal($process, SIGHUP);
    $deadline = microtime(true) + 3;
    while (!is_file($log) || !log_count($log, '/Reload complete/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(50_000);
    }
    probe($addr, '/throw');
    log_wait($log, '/RuntimeException: boom/');
    native_stop($process);
});

test('a log file that can\'t be reopened on SIGHUP is said in the log still open', function () {
    $dir     = temp_path(dir: true);
    $addr    = free_address();
    $process = swerve_spawn(["--http=$addr", '--workers=1', "--log=$dir/swerve.log", '--grace=2'], 'app.php');
    try {
        $deadline = microtime(true) + 5;
        while ('Hello' !== probe($addr, '/hello')) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(20_000);
        }
        $rotated = temp_path();
        rename("$dir/swerve.log", $rotated);
        chmod($dir, 0500);
        swerve_signal($process, SIGHUP);
        log_wait($rotated, "/warning .*Can't reopen the log file " . preg_quote("$dir/swerve.log", '/') . '/');
        native_stop($process);
    } finally {
        chmod($dir, 0700);
    }
});

test('FastCGI: SIGHUP reloads without failing requests', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir], mode: 'fastcgi');
    expect(fcgi_get($addr, '/version')['body'])->toBe('v1');
    write_version($dir, 'v2');
    swerve_signal($process, SIGHUP);

    $failed   = 0;
    $deadline = microtime(true) + 3;
    while (!log_count($log, '/Reload complete/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        $failed += (int) (null === fcgi_get($addr, '/version'));
    }
    expect($failed)->toBeLessThanOrEqual(resets_allowed());
    wait_version($addr, 'v2', 1, fastcgi: true);
    native_stop($process);
});

test('a reload whose new worker fails to start is retried, so a passing failure does not leave the old code serving', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    write_version($dir, 'v2');
    touch("$dir/crash"); // a database away for a moment
    swerve_signal($process, SIGHUP);
    log_wait($log, '/new worker for slot 0 exit 3 before becoming ready/');
    unlink("$dir/crash");

    log_wait($log, '/Reload complete/', 4);
    wait_version($addr, 'v2', 1);
    expect(log_count($log, '/recovered/'))->toBe(0);
    native_stop($process);
});

test('after log rotation, workers a failed reload leaves serving log to the new file', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(env: ['SWERVE_TEST_DIR' => $dir]);
    file_put_contents("$dir/version.php", "<?php return 'v2' +;\n");
    rename($log, "$log.1");
    $GLOBALS['swerve_temp'][] = "$log.1";
    swerve_signal($process, SIGHUP);
    log_wait($log, '/new worker for slot 0 exit 2 before becoming ready/');
    for ($i = 0; $i < 4; ++$i) {
        probe($addr, "/throw?after=rotate$i");
    }
    expect(log_wait($log, '/after=rotate\d failed/'))->toHaveCount(4);
    expect(log_count("$log.1", '/after=rotate/'))->toBe(0);
    native_stop($process);
});

/*
 * Under load
 */

test('reloads under concurrent load fail no requests', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    $load                   = http_load($addr, '/version', 5, 16, [
        [1.0, static function () use ($dir, $process) { write_version($dir, 'v2'); swerve_signal($process, SIGHUP); }],
        [3.0, static fn () => swerve_signal($process, SIGHUP)],
    ]);

    expect([$load['status5xx'], $load['truncated']])->toBe([0, 0]);
    expect($load['reset'])->toBeLessThanOrEqual(resets_allowed());
    expect($load['ok'])->toBeGreaterThan(500);
    expect(array_keys($load['bodies']))->toContain('v1', 'v2');
    foreach ($load['requests'] as [$start, , $outcome, $body]) {
        if ($start >= 4.0 && 'ok' === $outcome) {
            expect($body)->toBe('v2');
        }
    }
    expect(log_count($log, '/Reload complete/'))->toBeGreaterThanOrEqual(1);
    native_stop($process);
})->skip(fn () => (int) shell_exec('nproc') < 2, 'needs 2 cores');

test('recycling under concurrent load fails no requests, one replacement at a time', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=50'], workers: 4);
    $load                   = http_load($addr, '/hello', 4, 16);

    expect([$load['status5xx'], $load['truncated']])->toBe([0, 0]);
    expect($load['reset'])->toBeLessThanOrEqual(resets_allowed());
    expect(log_count($log, '/took over, draining \d+ \(recycle\)/'))->toBeGreaterThanOrEqual(8);
    // Between a replacement's start and its takeover, no other replacement starts
    preg_match_all('/replacing \d+|took over|Recycle of slot/', file_get_contents($log), $events);
    $starting = 0;
    foreach ($events[0] as $event) {
        $starting += str_starts_with($event, 'replacing') ? 1 : -1;
        expect($starting)->toBeLessThanOrEqual(1);
    }
    native_stop($process);
})->skip(fn () => (int) shell_exec('nproc') < 2, 'needs 2 cores');

test('faults under concurrent load: the others serve on, and the workers are replaced', function () {
    [$process, $addr] = swerve_start(['--watchdog=1'], workers: 4, php: ['-d', 'memory_limit=32M']);
    $faults           = [];
    $fault            = static function (string $path) use ($addr, &$faults) {
        return static function () use ($addr, $path, &$faults) { $faults[] = send_get($addr, $path); };
    };
    $load = http_load($addr, '/hello', 7, 16, [[1.0, $fault('/exit')], [2.0, $fault('/throw')], [3.0, $fault('/oom')], [3.5, $fault('/spin')]]);

    expect([$load['status5xx'], $load['truncated']])->toBe([0, 0]);
    // Resets come only from the dying workers: their requests in flight and accept queues. The
    // stuck one's queue fills with every client whose connection lands there, until the watchdog.
    foreach ($load['requests'] as [$start, $end, $outcome]) {
        if ('ok' !== $outcome) {
            expect($outcome)->toBeIn(['reset', 'refused']);
            expect(($end >= 1.0 && $end < 1.5) || ($end >= 3.0 && $end < 3.5) || ($end >= 3.5 && $end < 5.0))->toBeTrue("a reset at $end s");
        }
        if ($start >= 5.5) {
            expect($outcome)->toBe('ok');
        }
    }
    expect(worker_pids($addr, 4))->toHaveCount(4);
    native_stop($process);
})->skip(fn () => (int) shell_exec('nproc') < 2, 'needs 2 cores');

test('a drain waits for the coroutines a request started after its response, up to the deadline', function () {
    $dir                    = test_dir(null);
    [$process, $addr, $log] = swerve_start(['--grace=2'], workers: 1, env: ['SWERVE_TEST_DIR' => $dir]);
    expect(probe($addr, '/after-response?ms=500'))->toBe('ok');
    swerve_signal($process, SIGTERM);
    [$code] = swerve_wait($process, 5);
    expect([$code, @file_get_contents("$dir/after-response")])->toBe([0, 'done']);

    // Past the drain deadline (a second before the grace period ends) the work is dropped, and said so
    [$process, $addr, $log] = swerve_start(['--grace=2'], workers: 1, env: ['SWERVE_TEST_DIR' => $dir]);
    unlink("$dir/after-response");
    expect(probe($addr, '/after-response?ms=5000'))->toBe('ok');
    swerve_signal($process, SIGTERM);
    [$code, $took] = swerve_wait($process, 5);
    expect([$code, file_exists("$dir/after-response"), $took < 2])->toBe([0, false, true]);
    log_wait($log, '/Drain deadline reached .* the coroutines requests started/');
});

test('on a machine with several NUMA nodes, the workers are pinned to them in turn', function () {
    $nodes = glob('/sys/devices/system/node/node[0-9]*', GLOB_ONLYDIR);
    natsort($nodes);
    $lists = array_map(static fn ($node) => trim(file_get_contents("$node/cpulist")), array_values($nodes));
    [$process, $addr] = swerve_start([], count($lists));
    try {
        $pinned = array_map(static function (int $pid): string {
            preg_match('/^Cpus_allowed_list:\s*(\S+)/m', file_get_contents("/proc/$pid/status"), $m);

            return $m[1];
        }, worker_pids($addr, count($lists)));
        sort($pinned);
        sort($lists);
        expect($pinned)->toBe($lists); // one worker on each node
    } finally {
        native_stop($process);
    }
})->skip(fn () => count(glob('/sys/devices/system/node/node[0-9]*', GLOB_ONLYDIR)) < 2, 'needs several NUMA nodes');

test('shutdown under concurrent load fails no request in flight', function () {
    [$process, $addr] = swerve_start();
    $signalled        = 0.0;
    $load             = http_load($addr, '/sleep?ms=100&id=l', 3, 8, [[1.0, static function () use ($process, &$signalled) {
        $signalled = microtime(true);
        swerve_signal($process, SIGTERM);
    }]]);

    expect([$load['status5xx'], $load['truncated']])->toBe([0, 0]);
    expect($load['reset'])->toBeLessThanOrEqual(resets_allowed());
    [$code] = swerve_wait($process, 2);
    expect($code)->toBe(0);
    expect(microtime(true) - $signalled)->toBeLessThan(2.5);
})->skip(fn () => (int) shell_exec('nproc') < 2, 'needs 2 cores');

/*
 * Command line
 */

test('-d is gone from --help and is refused', function () {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' --help', $help, $code);
    expect(implode("\n", $help))->toContain('--grace')->toContain('--linger')->toContain('--watchdog')->toContain('--max-memory')->toContain('--max-requests')->not->toMatch('/^\s*-d\b/m');

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' -d 2>&1', $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain('Unknown flag: -d');
});

test('without -v, the log tells of reloads and shutdown, not of each worker draining', function () {
    $addr     = free_address();
    $log      = temp_path();
    $process  = swerve_spawn(["--http=$addr", '--workers=2', "--log=$log"], 'app.php');
    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    swerve_signal($process, SIGHUP);
    log_wait($log, '/Reload complete/');
    swerve_signal($process, SIGTERM);
    swerve_wait($process, 3);

    expect_log_order($log, ['/Reload requested/', '/took over, draining/', '/Reload complete/', '/Shutting down/', '/Stopped in/']);
    expect(log_count($log, '/exited after draining|Draining \(the master asked\)/'))->toBe(0);
});

test('a second swerve on an address already served is refused', function () {
    [$process, $addr] = swerve_start();
    $log              = temp_path();
    $second           = swerve_spawn(["--http=$addr", '--workers=2', "--log=$log"], 'app.php');
    [$code]           = swerve_wait($second, 3);

    expect($code)->not->toBe(0);
    expect(file_get_contents($log))->toContain('already in use');
    expect(probe($addr, '/hello'))->toBe('Hello');
    native_stop($process);
});

test('--help exits 0; an empty --log and a repeated address are refused', function () {
    $swerve = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php');
    exec("$swerve --help", $out, $code);
    expect($code)->toBe(0);

    $addr = free_address();
    $out  = [];
    exec("$swerve --log= --http=$addr missing.php 2>&1", $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain('Value required for option: --log=<path>');

    $out = [];
    exec("timeout 5 $swerve --http=$addr --http=$addr " . escapeshellarg(__DIR__ . '/Fixtures/app.php') . ' 2>&1', $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain("$addr more than once");
});

test('--watchdog below a second is refused', function () {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' --watchdog=0.5 --http=' . free_address() . ' missing.php 2>&1', $out, $code);
    expect($code)->toBe(2);
    expect(implode("\n", $out))->toContain('Seconds: 1 or more, or 0 for off');
});

test('options may follow the swerve file; a second file is refused', function () {
    $addr    = free_address();
    $log     = temp_path();
    $process = proc_open(['setsid', PHP_BINARY, __DIR__ . '/../bin/swerve.php', __DIR__ . '/Fixtures/app.php', "--http=$addr", '-w', '1', "--log=$log"], [], $pipes);
    try {
        expect(log_wait($log, '/with 1 worker$/m', 10))->not->toBeEmpty();
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' ' . escapeshellarg(__DIR__ . '/Fixtures/app.php') . ' other.php 2>&1', $out, $code);
    expect([$code, $out[0]])->toBe([2, 'swerve: Unknown argument: other.php']);
});

test('--http and --fastcgi take an IPv6 address', function (string $mode) {
    $probe = stream_socket_server('tcp://[::1]:0');
    $addr  = stream_socket_get_name($probe, false);
    fclose($probe);
    expect($addr)->toStartWith('[::1]:');
    $log      = temp_path();
    $process  = swerve_spawn(["--$mode=$addr", '--workers=1', "--log=$log"], 'app.php');
    $deadline = microtime(true) + 5;
    while ('Hello' !== ('http' === $mode ? probe($addr, '/hello') : fcgi_get($addr, '/hello')['body'] ?? null)) {
        expect(proc_get_status($process)['running'])->toBeTrue(file_get_contents($log));
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    native_stop($process);
})->with(['http', 'fastcgi'])->skip(fn () => false === @stream_socket_server('tcp://[::1]:0'), 'no IPv6');

test('-q keeps PHP\'s own errors off the terminal', function () {
    $addr     = free_address();
    $stdout   = temp_path();
    $process  = swerve_spawn(["--http=$addr", '--workers=1', '-q'], 'app.php', php: ['-d', 'memory_limit=32M', '-d', 'display_errors=1', '-d', 'log_errors=1'], out: [1 => ['file', $stdout, 'w'], 2 => ['file', $stdout, 'w']]);
    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    expect(probe($addr, '/oom'))->toBeNull();
    usleep(200_000);
    native_stop($process);

    expect(file_get_contents($stdout))->toBe('');
});

test('-q --log writes nothing to the terminal and everything to the file', function () {
    $addr    = free_address();
    $log     = temp_path();
    $stdout  = temp_path();
    $process = swerve_spawn(["--http=$addr", '--workers=2', '-q', '-vv', "--log=$log"], 'app.php', out: [1 => ['file', $stdout, 'w'], 2 => ['file', $stdout, 'w']]);
    $deadline = microtime(true) + 5;
    while ('Hello' !== probe($addr, '/hello')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    native_stop($process);

    expect(file_get_contents($stdout))->toBe('');
    expect(file_get_contents($log))->toMatch('/\.\d\d   \S/')->toMatch('/\.\d\d 0 /')->toMatch('/\.\d\d 1 /');
});

/*
 * Upgraded connections (101) during a drain: the application's input ends, as if the client
 * had closed its side, and the application ends its response; one that doesn't is dropped at
 * the drain deadline
 */

test('SIGTERM with a WebSocket open: the application sees its input end, says goodbye, and the worker drains in time', function () {
    [$process, $addr, $log, $pid] = swerve_start(['--grace=3'], workers: 1);
    $conn                         = ws_connect($addr, '/ws');
    ws_send($conn, 1, 'hello');
    expect(ws_read($conn))->toBe([1, 'hello']);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);

    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    expect(ws_read($conn))->toBeNull();
    fclose($conn);
    [$code, $seconds] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    log_wait($log, '/1 upgraded/', 1);
    log_wait($log, '/Drained in/', 1);
    expect(log_count($log, '/Drain deadline reached/'))->toBe(0);
    expect(group_gone($pid))->toBeTrue();
});

test('a reload with a WebSocket open: the old worker says goodbye and drains, a new one serves new WebSockets', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, env: ['SWERVE_TEST_DIR' => test_dir()]);
    $old                    = (int) probe($addr, '/pid');
    $conn                   = ws_connect($addr, '/ws');
    ws_send($conn, 1, 'hello');
    expect(ws_read($conn))->toBe([1, 'hello']);
    swerve_signal($process, SIGHUP);

    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
    expect(ws_read($conn))->toBeNull();
    fclose($conn);
    log_wait($log, '/Drained in/', 3);
    $deadline = microtime(true) + 3;
    while (in_array($pid = (int) probe($addr, '/pid'), [0, $old], true)) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    $conn = ws_connect($addr, '/ws');
    ws_send($conn, 1, 'again');
    expect(ws_read($conn))->toBe([1, 'again']);
    expect($pid)->not->toBe($old);
    native_stop($process);
});

test('a tunnel whose application ignores the end of its input is dropped at the drain deadline, logged', function () {
    [$process, $addr, $log, $pid] = swerve_start(['--grace=3'], workers: 1);
    $conn                         = native_connect($addr);
    fwrite($conn, "GET /upgrade-upper?ignore-eof=1 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(101);
    fwrite($conn, 'x');
    expect(fread($conn, 10))->toBe('X');
    $start = microtime(true);
    swerve_signal($process, SIGTERM);

    expect(native_closed($conn))->toBeTrue();
    expect(microtime(true) - $start)->toBeGreaterThan(1.8)->toBeLessThan(2.6); // max(grace - 1, grace / 2)
    [$code] = swerve_wait($process, 2);
    expect($code)->toBe(0);
    expect(group_gone($pid, 1))->toBeTrue();
    log_wait($log, '/Drain deadline reached/', 1);
    expect(log_count($log, '/FiberError/'))->toBe(0, file_get_contents($log));
});

test('a drain deadline reached while the application reads a request body after the response ends the worker without a PHP fatal error', function (string $request) {
    [$process, $addr, $log] = swerve_start(['--grace=3'], workers: 1);
    $conn                   = native_connect($addr);
    fwrite($conn, $request);
    if (str_contains($request, 'late-read')) {
        expect(native_read_response($conn)['status'])->toBe(202);
    } else {
        usleep(200_000);
    }
    swerve_signal($process, SIGTERM);
    // A byte now and then: the application's read waits on the socket, within its allowance
    $start = microtime(true);
    set_error_handler(static fn (): bool => true); // the worker may be gone
    try {
        while (proc_get_status($process)['running'] && microtime(true) - $start < 5) {
            fwrite($conn, 'x');
            usleep(300_000);
        }
    } finally {
        restore_error_handler();
    }
    [$code] = swerve_wait($process, 2);

    expect($code)->toBe(0);
    expect(file_get_contents($log))->toContain('Drain deadline reached')->not->toContain('Fatal error')->not->toContain('exit 255');
})->with([
    'read after the response'             => ["POST /late-read?ms=0 HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\nx"],
    'waiting for an upgrade\'s status'    => ["POST /late-probe?ms=10000 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: h2c\r\nContent-Length: 0\r\n\r\n"],
]);

test('a request body read slowly after the response is read to its end during a drain', function () {
    $dir                    = test_dir();
    [$process, $addr, $log] = swerve_start(['--grace=10'], workers: 1, env: ['SWERVE_TEST_DIR' => $dir]);
    $conn                   = native_connect($addr);
    // Larger than 64 KiB, so the application reads it from the socket: 20000 bytes at a time,
    // 1.2 s apart
    $body = str_repeat('x', 70000);
    fwrite($conn, "POST /slow-consume?ms=1200&size=20000 HTTP/1.1\r\nHost: t\r\nContent-Length: 70000\r\n\r\n$body");
    expect(native_read_response($conn)['status'])->toBe(202);
    swerve_signal($process, SIGTERM);

    [$code, $seconds] = swerve_wait($process, 10);
    expect($code)->toBe(0);
    expect($seconds)->toBeGreaterThan(4.0);
    expect(file_get_contents("$dir/late"))->toBe(md5($body) . ':70000');
    expect(file_get_contents($log))->not->toContain('held unread')->not->toContain('Drain deadline');
});

/**
 * An upgraded /upgrade-upper connection, non-blocking: the fixture echoes what it reads
 * upper-cased, and says "EOF\n" and ends when its input ends.
 *
 * @return resource
 */
function upper_tunnel(string $addr, ?int $rcvbuf = null)
{
    $conn = native_connect($addr);
    if (null !== $rcvbuf) {
        socket_set_option(socket_import_stream($conn), SOL_SOCKET, SO_RCVBUF, $rcvbuf);
    }
    fwrite($conn, "GET /upgrade-upper HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(101);
    stream_set_blocking($conn, false);

    return $conn;
}

test('a tunnel whose client keeps sending during a drain still sees its input end, says goodbye, and closes cleanly', function () {
    [$process, $addr, $log] = swerve_start(['--grace=6'], workers: 1);
    $conn                   = upper_tunnel($addr);
    // Flat out, reading all the while, until the goodbye arrives
    $received = '';
    $signal   = null;
    $start    = microtime(true);
    while (!str_ends_with($received, "EOF\n") && microtime(true) - $start < 8) {
        if (null === $signal && microtime(true) - $start > 1) {
            swerve_signal($process, SIGTERM);
            $signal = microtime(true);
        }
        expect(@fwrite($conn, str_repeat('a', 16384)))->not->toBeFalse();
        $received .= (string) @fread($conn, 65536);
    }
    $seconds = microtime(true) - $signal;
    stream_socket_shutdown($conn, STREAM_SHUT_WR);
    [, $reset] = read_to_end($conn);

    expect(str_ends_with($received, "EOF\n"))->toBeTrue();
    expect($seconds)->toBeLessThan(1.0);
    expect($reset)->toBeFalse();
    [$code] = swerve_wait($process, 4);
    expect($code)->toBe(0);
    expect(log_count($log, '/Drain deadline reached/'))->toBe(0);
});

test('a drained tunnel whose client still sends gets everything the application wrote, its goodbye included, without a reset', function () {
    [$process, $addr] = swerve_start(['--grace=6'], workers: 1);
    $conn             = upper_tunnel($addr, 65536);
    // 1 MiB without reading: the echo waits in the buffers, the worker's writes stall
    $sent  = 0;
    $start = microtime(true);
    while ($sent < 1048576 && microtime(true) - $start < 3) {
        $n     = fwrite($conn, str_repeat('a', min(65536, 1048576 - $sent)));
        $sent += $n;
        if (0 === $n) {
            usleep(10_000);
        }
    }
    usleep(1_000_000);
    // Still sending during the drain, then reading everything
    $start = microtime(true);
    while (microtime(true) - $start < 0.8) {
        if (!isset($signal) && microtime(true) - $start > 0.3) {
            swerve_signal($process, SIGTERM);
            $signal = true;
        }
        fwrite($conn, str_repeat('a', 1024));
        usleep(10_000);
    }
    [$received, $reset] = read_to_end($conn);

    expect($reset)->toBeFalse();
    expect(str_ends_with($received, "EOF\n"))->toBeTrue();
    expect(trim($received, "A\n"))->toBe('EOF');
    swerve_wait($process, 6);
})->skip(!function_exists('socket_create'), 'the test uses ext-sockets');

test('a drain ends a tunnel\'s input also while its application reads the upgrade request\'s own body, sent after the 101', function (string $framing, string $first, string $more) {
    [$process, $addr, $log] = swerve_start(['--grace=4'], workers: 1);
    $conn                   = native_connect($addr);
    fwrite($conn, "GET /upgrade-upper HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n$framing\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(101);
    fwrite($conn, $first);
    expect(read_until($conn, 'ABC'))->toBe('ABC');
    stream_set_blocking($conn, false);
    swerve_signal($process, SIGTERM);
    // The client may go on sending the body: its input ends all the same
    $received = '';
    $start    = microtime(true);
    while (!str_ends_with($received, "EOF\n") && microtime(true) - $start < 3.5) {
        if ('' !== $more) {
            @fwrite($conn, $more);
        }
        usleep(50_000);
        $received .= (string) @fread($conn, 65536);
    }

    expect(str_ends_with($received, "EOF\n"))->toBeTrue();
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    [$code] = swerve_wait($process, 4);
    expect($code)->toBe(0);
    expect(log_count($log, '/Drain deadline reached/'))->toBe(0);
})->with([
    'Content-Length, 3 of its 10 bytes sent' => ['Content-Length: 10', 'abc', ''],
    'chunked, sent on and on'                => ['Transfer-Encoding: chunked', "3\r\nabc\r\n", "4\r\nabcd\r\n"],
]);

test('a drain ends a tunnel\'s input at once also when its application waits since before the 101 for the upgrade request\'s own body', function (string $framing) {
    [$process, $addr, $log] = swerve_start(['--grace=4'], workers: 1);
    $conn                   = native_connect($addr);
    // The fixture's reader starts before handle() returns the 101, and finds no body bytes yet
    fwrite($conn, "GET /upgrade-upper HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n$framing\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(101);
    usleep(300_000);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    stream_set_blocking($conn, false);
    [$received] = read_to_end($conn, 3.5);

    expect($received)->toBe("EOF\n");
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    [$code] = swerve_wait($process, 4);
    expect($code)->toBe(0);
    expect(log_count($log, '/Drain deadline reached/'))->toBe(0);
})->with([
    'Content-Length' => ['Content-Length: 10'],
    'chunked'        => ['Transfer-Encoding: chunked'],
]);

test('a 101 decided during a drain is sent, and its input ends at once', function () {
    [$process, $addr, $log] = swerve_start(['--grace=3'], workers: 1);
    $conn                   = native_connect($addr);
    fwrite($conn, "GET /upgrade-upper?delay=500 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n");
    usleep(100_000);
    swerve_signal($process, SIGTERM);

    expect(native_read_head($conn)['status'])->toBe(101);
    expect(stream_get_contents($conn))->toBe("EOF\n");
    fclose($conn);
    [$code] = swerve_wait($process, 3);
    expect($code)->toBe(0);
    log_wait($log, '/Drained in/', 1);
});

test('options take their value attached or as the next word, and addresses may be a port, :port or host:port', function (array $args, string $listening) {
    $log     = temp_path();
    $process = swerve_spawn([...$args, "--log=$log"], 'app.php');
    try {
        expect(log_wait($log, '/serving \S+ on http:\/\/(\S+) with 1 worker$/m', 10)[0][1])->toBe($listening);
    } finally {
        native_stop($process);
    }
})->with([
    '-w 1, a port'       => [['-w', '1', '--http=18977'], '127.0.0.1:18977'],
    '--workers 1, :port' => [['--workers', '1', '--http', ':18978'], '0.0.0.0:18978'],
    'host:port'          => [['-w1', '--http=localhost:18979'], '127.0.0.1:18979'],
]);

test('a usage error is one line on stderr, pointing at --help, with exit code 2', function (string $args, string $error) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . " $args 2>&1 >/dev/null", $out, $code);
    expect([$code, $out])->toBe([2, ["swerve: $error", 'Run `swerve --help` for the options.']]);
})->with([
    'unknown option' => ['--bogus', 'Unknown option: --bogus'],
    'bad port'       => ['--http=:99999', 'Illegal value for option: --http: a port from 1 to 65535 required'],
    'unknown host'   => ['--http=no-such-host.invalid:80', "Illegal value for option: --http: can't resolve no-such-host.invalid"],
]);

test('--version names swerve, PHP, phasync and phasync-ext', function () {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/swerve.php') . ' --version', $out, $code);
    expect($code)->toBe(0);
    expect($out[0])->toMatch('/^swerve \S+ \(PHP \S+, phasync \S+, phasync-ext .+\)$/');
});

test('each request is logged with its worker\'s slot, method, target, status and time; --no-access-log turns that off', function (array $args, int $lines) {
    [$process, $addr, $log] = swerve_start($args, 1);
    $body                   = probe($addr, '/hello?x=1');
    native_stop($process);
    expect($body)->toBe('Hello');
    expect(preg_match_all('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d\d 0 GET \/hello\?x=1 200 [\d.]+ms$/m', file_get_contents($log)))->toBe($lines, file_get_contents($log));
})->with([
    'by default'      => [[], 1],
    '--no-access-log' => [['--no-access-log'], 0],
]);

test('Swerve::log() logs in swerve\'s format, with the worker\'s slot', function () {
    [$process, $addr, $log] = swerve_start([], 1);
    expect(probe($addr, '/app-log'))->toBe('logged');
    native_stop($process);
    expect(file_get_contents($log))->toMatch('/\.\d\d 0 warning +from the application: hi$/m');
});

test('the application may start coroutines as it loads; they run for the worker\'s life, and don\'t hold up its drain', function () {
    [$process, $addr, $log] = swerve_start([], 1, fixture: 'background.php', wait: false);
    $deadline = microtime(true) + 5;
    while (null === ($ticks = probe($addr, '/'))) {
        expect(microtime(true))->toBeLessThan($deadline, file_get_contents($log));
        usleep(20_000);
    }
    usleep(300_000);
    expect((int) probe($addr, '/'))->toBeGreaterThan((int) $ticks);
    swerve_signal($process, SIGTERM);
    [$code, $took] = swerve_wait($process, 5);
    expect([$code, $took < 2])->toBe([0, true]);
    expect(log_count($log, '/(error|critical|warning)/'))->toBe(0, file_get_contents($log));
});

test('a worker\'s accepts leave no error behind for the application\'s shutdown handler to report', function (string $mode) {
    $dir = temp_path(true);
    \touch("$dir/record-last-error");
    [$process, $addr] = swerve_start([], 1, env: ['SWERVE_TEST_DIR' => $dir], mode: $mode);
    'http' === $mode ? probe($addr, '/hello') : fcgi_get($addr, '/hello');
    native_stop($process);
    $recorded = \array_map('file_get_contents', \glob("$dir/last-error-*"));
    expect($recorded)->not->toBeEmpty();
    expect(\array_filter($recorded))->toBe([]); // phasync/swerve#2: "stream_socket_accept(): Accept failed"
})->with(['http', 'fastcgi']);

test('startup: the log recommends phasync-ext when it is not loaded, and says nothing when it is', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    native_stop($process);
    $notice = '/phasync-ext is not loaded/';
    expect(log_count($log, $notice))->toBe(extension_loaded('phasync') ? 0 : 1, file_get_contents($log));
});
