<?php

// Swerve\Server: an HTTP server the application starts in its own process (a CLI application with
// a web interface), serving the handler forms Swerve::accept() takes. Fixture:
// tests/Fixtures/server/servers.php.

/** @return array{0: resource, 1: resource, 2: resource, 3: array<string, string>} the process, its stdin, its stdout, and the address of each server */
function server_start(): array
{
    $ext = getenv('PHASYNC_EXT_SO') ?: throw new RuntimeException('Swerve\Server needs ext-phasync: set PHASYNC_EXT_SO');
    $process = proc_open(
        [PHP_BINARY, '-d', "extension=$ext", __DIR__ . '/Fixtures/server/servers.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $line = fgets($pipes[1]);
    if (false === $line) {
        throw new RuntimeException('The server fixture printed no addresses');
    }

    return [$process, $pipes[0], $pipes[1], json_decode($line, true)];
}

/** "close\n" to the fixture: what it printed until it exited. */
function server_close($process, $stdin, $stdout): string
{
    fwrite($stdin, "close\n");
    $out = stream_get_contents($stdout);
    proc_close($process);

    return $out;
}

it('serves each handler form Swerve::accept() takes, and closes', function () {
    [$process, $stdin, $stdout, $addr] = server_start();
    try {
        expect(probe($addr['psr7'], '/a'))->toBe('psr7 /a');
        expect(probe($addr['sapi'], '/b'))->toBe('sapi /b');
        expect(probe($addr['client'], '/c'))->toBe('client /c');
        expect(probe($addr['static'], '/file.txt'))->toBe('static-body');

        $conn = native_connect($addr['sapi']);
        fwrite($conn, "GET /h HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['headers']['x-form'] ?? null)->toBe('sapi');
    } finally {
        $out = server_close($process, $stdin, $stdout);
    }
    expect($out)->toBe("closed\nexited\n");
});

it('ends its requests with close(): onShutdown() runs, awaitShutdown() returns, draining() is true', function () {
    [$process, $stdin, $stdout, $addr] = server_start();
    $conn = native_connect($addr['sse']);
    fwrite($conn, "GET /events HTTP/1.1\r\nHost: t\r\n\r\n");
    read_until($conn, "data: ready\n\n");
    $out  = server_close($process, $stdin, $stdout);
    $rest = stream_get_contents($conn);
    expect($rest)->toContain("data: closing\n\n");
    expect($rest)->toContain("data: draining=true\n\n");
    expect($out)->toBe("closed\nexited\n");
});

it('stops accepting once closed', function () {
    [$process, $stdin, $stdout, $addr] = server_start();
    server_close($process, $stdin, $stdout);
    expect(probe($addr['psr7'], '/a'))->toBeNull();
});
