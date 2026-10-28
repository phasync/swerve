<?php

/*
 * Reload (SIGHUP, --watch) and stop (SIGTERM, SIGINT), after PM2 and Node's cluster: new workers
 * on the code as it is now, the old ones finish what they serve; a stop finishes the requests in
 * flight within --grace.
 */

/** Send GET $path on a new connection; read the response later with reload_read(). */
function reload_send(string $addr, string $path)
{
    $conn = native_connect($addr);
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    return $conn;
}

/** The body of a 200 on $conn, or what came instead. */
function reload_read($conn): string
{
    $response = (string) stream_get_contents($conn);
    fclose($conn);

    return str_starts_with($response, 'HTTP/1.1 200') ? substr($response, strpos($response, "\r\n\r\n") + 4) : "not a 200: $response";
}

/** A directory with index.php saying $text, for swerve -S. */
function reload_site(string $text): string
{
    $dir = temp_path(true);
    file_put_contents("$dir/index.php", "<?php echo '$text';");

    return $dir;
}

test('a reload loses no request: those in flight finish in the old workers, the next go to the new ones', function () {
    [$process, $addr, $log] = swerve_start(workers: 2, fixture: 'reload.php');
    $before = [];
    for ($i = 0; $i < 3; ++$i) {
        $before[$pid = probe($addr, '/pid')] = true;
    }
    $inFlight = [];
    for ($i = 0; $i < 10; ++$i) {
        $inFlight[$i] = reload_send($addr, "/sleep?ms=500&id=$i");
    }
    usleep(100_000);
    swerve_signal($process, SIGHUP);
    $during = [];
    for ($i = 0; $i < 20; ++$i) {
        $during[] = probe($addr, '/hello', 5.0);
        usleep(20_000);
    }
    foreach ($inFlight as $i => $conn) {
        expect(reload_read($conn))->toBe("slept $i");
    }
    expect(array_unique($during))->toBe(['Hello']);
    log_wait($log, '/Reload complete/', 8);
    $deadline = microtime(true) + 5;
    do {
        $pid = probe($addr, '/pid');
    } while (isset($before[$pid]) && microtime(true) < $deadline);
    expect(isset($before[$pid]))->toBeFalse();
    native_stop($process);
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING|Warning:)/'))->toBe(0, (string) file_get_contents($log));
});

test('swerve -S: a reload serves the code as it is now', function () {
    $dir = reload_site('old');
    $addr = free_address();
    $process = swerve_spawn(["--http=$addr", '--workers=1', '-S'], null, cwd: $dir);
    $deadline = microtime(true) + 10;
    while ('old' !== probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    file_put_contents("$dir/index.php", "<?php echo 'new';");
    expect(probe($addr, '/'))->toBe('old'); // nothing checks timestamps
    swerve_signal($process, SIGHUP);
    $deadline = microtime(true) + 10;
    while ('new' !== probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    native_stop($process);
});

test('--watch reloads when a PHP file changes', function () {
    $dir     = reload_site('old');
    $addr    = free_address();
    $log     = temp_path();
    $process = swerve_spawn(["--http=$addr", '--workers=1', '--watch', "--log=$log", '-S'], null, cwd: $dir);
    $deadline = microtime(true) + 10;
    while ('old' !== probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    file_put_contents("$dir/index.php", "<?php echo 'new, and longer';");
    $deadline = microtime(true) + 10;
    while ('new, and longer' !== probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline, (string) file_get_contents($log));
        usleep(50_000);
    }
    expect(file_get_contents($log))->toContain('index.php changed: reloading');
    native_stop($process);
});

test('a stop finishes the requests in flight and exits 0; new connections are refused', function () {
    [$process, $addr, $log] = swerve_start(workers: 2, fixture: 'reload.php');
    $inFlight = [];
    for ($i = 0; $i < 6; ++$i) {
        $inFlight[$i] = reload_send($addr, "/sleep?ms=400&id=$i");
    }
    usleep(100_000);
    swerve_signal($process, SIGTERM);
    usleep(100_000);
    expect(probe($addr, '/hello'))->toBeNull();
    foreach ($inFlight as $i => $conn) {
        expect(reload_read($conn))->toBe("slept $i");
    }
    [$code, $seconds] = swerve_wait($process, 5);
    expect($code)->toBe(0);
    expect($seconds)->toBeLessThan(2.0);
});

test('what still runs after --grace is killed; a second SIGTERM kills at once', function () {
    [$process, $addr, $log] = swerve_start(['--grace=0.5'], workers: 1, fixture: 'reload.php');
    $conn = reload_send($addr, '/sleep?ms=10000&id=0&shielded=1');
    usleep(100_000);
    swerve_signal($process, SIGTERM);
    [$code, $seconds] = swerve_wait($process, 5);
    expect([$code, $seconds < 2.0])->toBe([0, true], (string) file_get_contents($log));
    expect(file_get_contents($log))->toContain('still ran after the grace period');

    [$process, $addr, $log] = swerve_start(workers: 1, fixture: 'reload.php');
    $conn = reload_send($addr, '/sleep?ms=10000&id=0&shielded=1');
    usleep(100_000);
    swerve_signal($process, SIGTERM);
    usleep(200_000);
    swerve_signal($process, SIGTERM);
    [$code, $seconds] = swerve_wait($process, 5);
    expect([$code, $seconds < 1.0])->toBe([0, true]);
});
