<?php

// Swerve 0.2: the master accepts every connection, hands a readable one to a worker, and takes
// it back after each kept-alive response; workers loop worker.php through run_next(), each
// running the app's swerve.php, whose Swerve::accept() calls serve the requests.

function accept_start(string $fixture, int $workers = 2): array
{
    $ext = getenv('PHASYNC_EXT_SO');
    if (!$ext) {
        throw new RuntimeException('These tests need ext-phasync with run_next(): set PHASYNC_EXT_SO');
    }
    $addr    = free_address();
    $log     = temp_path();
    $process = proc_open(
        ['setsid', PHP_BINARY, '-d', "extension=$ext", __DIR__ . '/../bin/swerve.php', "--http=$addr", "--workers=$workers", "--log=$log", __DIR__ . "/Fixtures/accept/$fixture"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        __DIR__ . '/Fixtures/accept',
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $deadline                   = microtime(true) + 10;
    while (null === probe($addr, '/hello')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving on $addr:\n" . file_get_contents($log));
        }
        usleep(20000);
    }

    return [$process, $addr, $log];
}

/** A request on an open connection: the response, or null when the connection ended first. */
function accept_request($conn, string $path, string $extra = ''): ?array
{
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\n$extra\r\n");

    return native_read_response($conn);
}

it('serves a closure from a while (accept()) loop in every worker', function () {
    [, $addr] = accept_start('hello.php', 2);
    $pids     = [];
    for ($i = 0; $i < 40; ++$i) {
        $body = probe($addr, '/');
        expect($body)->toMatch('/^Hello from \d+\n$/');
        $pids[$body] = true;
    }
    expect(count($pids))->toBe(2);
});

it('keeps a connection alive across requests, each handed to whichever worker is free', function () {
    [, $addr] = accept_start('hello.php', 2);
    $conn     = native_connect($addr);
    $start    = microtime(true);
    for ($i = 0; $i < 20; ++$i) {
        $response = accept_request($conn, '/');
        expect($response['status'])->toBe(200)
            ->and($response['body'])->toMatch('/^Hello from \d+\n$/');
    }
    // Each takes about a millisecond; Nagle without TCP_NODELAY made each 40 ms
    expect(microtime(true) - $start)->toBeLessThan(0.4);
    fclose($conn);
});

it('runs a script handler as a fresh request each time, with its globals', function () {
    [, $addr] = accept_start('classic.php', 2);
    for ($i = 0; $i < 30; ++$i) {
        expect(probe($addr, "/page$i"))->toMatch("/^site-\\d+ count=1 uri=\\/page$i\\n$/");
    }
});

it('answers every request of many parallel keep-alive clients exactly once, never empty', function () {
    [, $addr] = accept_start('classic.php', 3);
    $clients  = [];
    for ($c = 0; $c < 16; ++$c) {
        $clients[$c] = native_connect($addr);
    }
    for ($round = 0; $round < 10; ++$round) {
        foreach ($clients as $c => $conn) {
            fwrite($conn, "GET /c$c-r$round HTTP/1.1\r\nHost: test\r\n\r\n");
        }
        foreach ($clients as $c => $conn) {
            $response = native_read_response($conn);
            expect($response['status'])->toBe(200)
                ->and($response['body'])->toMatch("/ count=1 uri=\\/c$c-r$round\\n$/");
        }
    }
});

it('closes the connection when the application says Connection: close', function () {
    [, $addr] = accept_start('classic.php', 1);
    $conn     = native_connect($addr);
    $response = accept_request($conn, '/close');
    expect($response['headers']['connection'] ?? '')->toBe('close')
        ->and(native_closed($conn))->toBeTrue();
});

it('runs a PSR-7 closure: ServerRequestInterface in, ResponseInterface out', function () {
    [, $addr] = accept_start('psr7.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "POST /psr HTTP/1.1\r\nHost: test\r\nContent-Length: 4\r\n\r\nbody");
    $response = native_read_response($conn);
    expect($response['status'])->toBe(201)
        ->and($response['headers']['x-method'])->toBe('POST')
        ->and($response['body'])->toBe('psr7 /psr body');
});

it('replaces a worker that was killed', function () {
    [, $addr] = accept_start('hello.php', 1);
    $pid      = (int) substr(probe($addr, '/'), strlen('Hello from '));
    posix_kill($pid, SIGKILL);
    $deadline = microtime(true) + 5;
    while (null === ($body = probe($addr, '/')) && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect($body)->toMatch('/^Hello from \d+\n$/')
        ->and((int) substr($body, strlen('Hello from ')))->not->toBe($pid);
});
