<?php

/*
 * A client that is slow, or reads nothing, must not stall the worker: while it holds a stream
 * open (Server-Sent Events, a WebSocket, a large response), a quick request in the same worker
 * is answered at once. A blocking call anywhere in the stream's path would show here, most
 * plainly without phasync-ext, where nothing is turned into a wait for the application.
 */

/** The slowest of $times quick requests to a one-worker server, in milliseconds. */
function slowest_answer(string $addr, int $times = 20): float
{
    $slowest = 0.0;
    for ($i = 0; $i < $times; ++$i) {
        $start = microtime(true);
        expect(probe($addr, '/hello', 5.0))->toBe('Hello');
        $slowest = max($slowest, (microtime(true) - $start) * 1000);
        usleep(10_000);
    }

    return $slowest;
}

test('Server-Sent Events to a client that reads nothing do not stall the worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /sse?n=1000000000&ms=0 HTTP/1.1\r\nHost: t\r\n\r\n");
        usleep(300_000); // until the socket buffers and the stream's buffer are full
        expect(slowest_answer($addr))->toBeLessThan(100.0);
        // and the stream goes on when the client reads
        expect(native_read_head($conn)['status'])->toBe(200);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('Server-Sent Events to a client that reads a little at a time do not stall the worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /sse?n=1000000000&ms=0 HTTP/1.1\r\nHost: t\r\n\r\n");
        $slowest = 0.0;
        for ($i = 0; $i < 20; ++$i) {
            fread($conn, 512);
            $start   = microtime(true);
            expect(probe($addr, '/hello', 5.0))->toBe('Hello');
            $slowest = max($slowest, (microtime(true) - $start) * 1000);
            usleep(20_000);
        }
        expect($slowest)->toBeLessThan(100.0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('a WebSocket whose client sends and never reads does not stall the worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/ws');
        $text = str_repeat('x', 60_000);
        stream_set_timeout($conn, 0, 300_000);
        set_error_handler(static fn (): bool => true); // the last send times out part way
        try {
            do { // the echoes fill every buffer on the way back, then the worker reads no more
                ws_send($conn, 1, $text);
            } while (!stream_get_meta_data($conn)['timed_out']);
        } finally {
            restore_error_handler();
        }
        expect(slowest_answer($addr))->toBeLessThan(100.0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('a WebSocket frame sent a few bytes at a time does not stall the worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/ws');
        $mask = "\x01\x02\x03\x04";
        $frame = "\x81" . chr(0x80 | 100) . $mask . (str_repeat('y', 100) ^ str_repeat($mask, 25));
        fwrite($conn, substr($frame, 0, 10));
        usleep(100_000);
        expect(slowest_answer($addr))->toBeLessThan(100.0);
        fwrite($conn, substr($frame, 10));
        expect(ws_read($conn)[0])->toBe(1);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('a large response to a client that reads nothing does not stall the worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /big?n=100000000 HTTP/1.1\r\nHost: t\r\n\r\n");
        usleep(300_000);
        expect(slowest_answer($addr))->toBeLessThan(100.0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});
