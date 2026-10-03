<?php

/*
 * Output outside the response (docs/stray-output.md): without phasync-ext a guard at the bottom
 * of the worker's output buffers ends the worker with a message on stderr; with it, none is
 * installed. The ext tests load the extension through PHP_INI_SCAN_DIR, as the suite does when
 * it runs with the extension loaded; the others run only without it.
 */

use Swerve\Http\Virtual;

/**
 * Start swerve serving tests/Fixtures/stray.php with one worker, its stderr in a file.
 *
 * @param bool $ext whether the extension is loaded
 *
 * @return array{0: resource, 1: string, 2: string, 3: string} the process, its address, the log, the stderr file
 */
function stray_start(array $env = [], bool $ext = false, bool $wait = true): array
{
    $addr = free_address();
    $log  = temp_path();
    $err  = temp_path();
    if ($ext && !Virtual::available()) {
        $dir = temp_path(dir: true);
        file_put_contents("$dir/phasync.ini", 'extension=' . getenv('PHASYNC_EXT'));
        $env['PHP_INI_SCAN_DIR'] = ":$dir";
    }
    $process  = swerve_spawn(["--http=$addr", '--workers=1', "--log=$log", '-vv', '--grace=3', '--watchdog=3'], 'stray.php', [], $env, [2 => ['file', $err, 'w']]);
    $deadline = microtime(true) + 10;
    while ($wait && null === probe($addr, '/hello')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving on $addr:\n" . file_get_contents($log) . file_get_contents($err));
        }
        usleep(20000);
    }

    return [$process, $addr, $log, $err];
}

/** The line of tests/Fixtures/stray.php that carries $marker in a comment. */
function stray_line(string $marker): int
{
    foreach (file(__DIR__ . '/Fixtures/stray.php') as $i => $line) {
        if (str_contains($line, "// $marker")) {
            return $i + 1;
        }
    }
    throw new LogicException("No $marker in the fixture");
}

/** Wait until the stderr file has the guard's message. */
function stray_message(string $err, float $timeout = 5): string
{
    $deadline = microtime(true) + $timeout;
    while (!str_contains((string) file_get_contents($err), 'Remedy:')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("No stray output message within $timeout s:\n" . file_get_contents($err));
        }
        usleep(20000);
    }

    return file_get_contents($err);
}

/** Wait until a worker other than $pid answers, as the master respawns it. */
function stray_respawned(string $addr, int $pid, float $timeout = 10): int
{
    $deadline = microtime(true) + $timeout;
    while (true) {
        $now = probe($addr, '/pid');
        if (null !== $now && (int) $now !== $pid) {
            return (int) $now;
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException("No new worker within $timeout s");
        }
        usleep(50000);
    }
}

$withoutExt = fn () => Virtual::available();

test('Stray output: echoing during a request ends the worker with the message on stderr; the master respawns it', function () {
    [$process, $addr, $log, $err] = stray_start();
    try {
        expect(probe($addr, '/level'))->toBe('1'); // the guard
        $pid  = (int) probe($addr, '/pid');
        $conn = native_connect($addr);
        fwrite($conn, "GET /echo?m=y HTTP/1.1\r\nHost: t\r\n\r\n");
        $text = stray_message($err);
        expect($text)->toStartWith('Stray output is not compatible with swerve.');
        expect($text)->toContain('"stray y"')->toContain('(7 bytes)');
        expect($text)->toContain('GET /echo?m=y');
        expect($text)->toContain('stray.php:' . stray_line('STRAY-ECHO'));
        expect($text)->toContain('php-fpm')->toContain('phasync-ext');
        expect(native_read_response($conn))->toBeNull(); // the request died with its worker

        expect(stray_respawned($addr, $pid))->not->toBe($pid);
        expect(probe($addr, '/hello'))->toBe('Hello');
        expect(log_count($log, "/Worker $pid .*died: exit \\d+/"))->toBe(1, file_get_contents($log));
    } finally {
        native_stop($process);
    }
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: echoing while loading swerve.php ends the worker, and the application fails to start', function () {
    [$process, , $log, $err] = stray_start(['SWERVE_TEST_ECHO_ON_LOAD' => '1'], wait: false);
    [$code] = swerve_wait($process, 10);
    $text   = file_get_contents($err);
    expect($text)->toStartWith('Stray output is not compatible with swerve.');
    expect($text)->toContain('"echoed while loading\n"')->toContain('stray.php:' . stray_line('STRAY-LOAD'));
    expect($text)->toContain('Request: none');
    expect($code)->not->toBe(0);
    expect(log_count($log, '/failed to start/'))->toBeGreaterThan(0, file_get_contents($log));
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: with overlapping requests the message names the one that echoed', function () {
    [$process, $addr, , $err] = stray_start();
    try {
        $pid   = (int) probe($addr, '/pid');
        $quiet = native_connect($addr);
        fwrite($quiet, "GET /sleep?ms=1500 HTTP/1.1\r\nHost: t\r\n\r\n");
        usleep(50000);
        $noisy = native_connect($addr);
        fwrite($noisy, "GET /echo-after?ms=100 HTTP/1.1\r\nHost: t\r\n\r\n");
        $text = stray_message($err);
        expect($text)->toContain('GET /echo-after?ms=100')->not->toContain('/sleep');
        expect($text)->toContain('stray.php:' . stray_line('STRAY-LATE'));
        stray_respawned($addr, $pid);
    } finally {
        native_stop($process);
    }
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: a coroutine the request started is traced to the request', function () {
    [$process, $addr, , $err] = stray_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /echo-child HTTP/1.1\r\nHost: t\r\n\r\n");
        $text = stray_message($err);
        expect($text)->toContain('GET /echo-child')->toContain('stray.php:' . stray_line('STRAY-CHILD'));
    } finally {
        native_stop($process);
    }
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: code that cleans up output buffers does not remove the guard', function () {
    [$process, $addr, , $err] = stray_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /strip HTTP/1.1\r\nHost: t\r\n\r\n");
        $text = stray_message($err);
        expect($text)->toContain('"after strip"')->toContain('stray.php:' . stray_line('STRAY-STRIP'));
    } finally {
        native_stop($process);
    }
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: a handler of its own on top of the guard keeps working, and a stream is unaffected', function () {
    [$process, $addr, $log, $err] = stray_start();
    try {
        expect(probe($addr, '/captured'))->toBe('captured 1');
        $conn = native_connect($addr);
        fwrite($conn, "GET /sse?n=3&ms=20 HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe("data: 0\n\ndata: 1\n\ndata: 2\n\n");
        expect(probe($addr, '/hello'))->toBe('Hello');
        expect(file_get_contents($err))->toBe('');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
})->skip($withoutExt, 'runs without phasync-ext');

test('Stray output: with phasync-ext no guard is installed and the echoing application keeps working', function () {
    [$process, $addr, $log, $err] = stray_start(ext: true);
    try {
        expect(probe($addr, '/level'))->toBe('0');
        $pid = (int) probe($addr, '/pid');
        expect(probe($addr, '/echo'))->toBe('returned');
        expect(probe($addr, '/strip'))->toBe('returned');
        expect((int) probe($addr, '/pid'))->toBe($pid);
        expect(file_get_contents($err))->toBe('');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs phasync-ext: PHASYNC_EXT=/path/to/phasync.so');

test('Stray output: with phasync-ext, echoing while loading swerve.php is not an error', function () {
    [$process, $addr, , $err] = stray_start(['SWERVE_TEST_ECHO_ON_LOAD' => '1'], ext: true);
    try {
        expect(probe($addr, '/hello'))->toBe('Hello');
        expect(file_get_contents($err))->toBe('');
    } finally {
        native_stop($process);
    }
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs phasync-ext: PHASYNC_EXT=/path/to/phasync.so');
