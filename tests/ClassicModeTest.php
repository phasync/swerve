<?php

/*
 * swerve -S [dir]: bugs found while running real classic apps (WordPress, MediaWiki, Laravel)
 * under classic mode, reduced to minimal repros. Fixtures: tests/Fixtures/classic-mode/.
 */

/**
 * Start `swerve -S <dir>` and wait until it answers /hello.php.
 *
 * @return array{0: resource, 1: string}
 */
function classic_serve_start(string $dir): array
{
    $addr     = free_address();
    $process  = swerve_spawn(["--http=$addr", '--workers=1', '-S', $dir], null);
    $deadline = microtime(true) + 10;
    while (null === http_get($addr, '/hello.php')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve -S did not start serving on $addr");
        }
        usleep(20000);
    }

    return [$process, $addr];
}

// #106. Found with WordPress: two requests pipelined on one connection get one answer, then the
// connection closes. -S accepts once per run of its script; static files are not affected.
test('a request pipelined behind a PHP script-handled request is still answered, not dropped when the connection closes', function () {
    $dir = __DIR__ . '/Fixtures/classic-mode';
    [$process, $addr] = classic_serve_start($dir);
    try {
        $conn = native_connect($addr);
        // Two requests written back-to-back, without reading the first response first
        // (HTTP/1.1 pipelining), both asking to keep the connection alive. requests-and-responses.md:
        // "HTTP/1.1 keep-alive and pipelining are supported."
        $request = "GET /hello.php HTTP/1.1\r\nHost: t\r\nConnection: keep-alive\r\n\r\n";
        fwrite($conn, $request . $request);

        $first  = native_read_response($conn);
        $second = native_read_response($conn);

        expect($first['status'] ?? null)->toBe(200);
        expect($first['body'] ?? null)->toBe('hello');
        // Today: the connection is closed right after the first response (well within the 5s
        // keep-alive idle budget), so the second, already-received request is silently dropped
        // instead of being answered.
        expect($second)->not->toBeNull();
        expect($second['status'] ?? null)->toBe(200);
        expect($second['body'] ?? null)->toBe('hello');
    } finally {
        native_stop($process);
    }
});

test('pipelining two requests for the SAME static file on one connection answers both (control: the static-file path is not affected)', function () {
    $dir = __DIR__ . '/Fixtures/classic-mode';
    [$process, $addr] = classic_serve_start($dir);
    try {
        $conn    = native_connect($addr);
        $request = "GET /static.txt HTTP/1.1\r\nHost: t\r\nConnection: keep-alive\r\n\r\n";
        fwrite($conn, $request . $request);

        $first  = native_read_response($conn);
        $second = native_read_response($conn);

        expect([$first['status'] ?? null, $first['body'] ?? null])->toBe([200, 'static-body']);
        expect([$second['status'] ?? null, $second['body'] ?? null])->toBe([200, 'static-body']);
    } finally {
        native_stop($process);
    }
});
