<?php

/*
 * Swerve\ServerSentEvents, served by tests/Fixtures/realtime.php: the wire format, the framing, the head
 * flushed at once, Last-Event-ID, and a handler that ends when its client is gone.
 */

/**
 * GET $path as an EventSource does, and read the head.
 *
 * @param array<string, string> $headers
 *
 * @return array{0: resource, 1: array{status: int, headers: array<string, string>}}
 */
function sse_open(string $addr, string $path, array $headers = [], string $method = 'GET', string $version = '1.1'): array
{
    $conn = native_connect($addr);
    $head = "$method $path HTTP/$version\r\nHost: test\r\nAccept: text/event-stream\r\n";
    foreach ($headers as $name => $value) {
        $head .= "$name: $value\r\n";
    }
    fwrite($conn, "$head\r\n");

    return [$conn, native_read_head($conn)];
}

/** Every chunk of the body up to the last chunk, one string for each event. */
function sse_chunks($conn): array
{
    $chunks = [];
    while ('' !== ($chunk = native_read_chunk($conn)) && null !== $chunk) {
        $chunks[] = $chunk;
    }

    return $chunks;
}

test('the head is sent at once, as an event stream that nothing may cache or buffer, chunked on HTTP/1.1', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        // The first event is 1.5 s away: the head must not wait for it
        $start         = microtime(true);
        [$conn, $head] = sse_open($addr, '/sse?n=1&wait=1500');
        expect(microtime(true) - $start)->toBeLessThan(1.0);
        expect($head['status'])->toBe(200);
        expect($head['headers']['content-type'])->toBe('text/event-stream');
        expect($head['headers']['cache-control'])->toBe('no-cache');
        expect($head['headers']['transfer-encoding'])->toBe('chunked');
        expect($head['headers'])->not->toHaveKey('content-length');
        expect(sse_chunks($conn))->toBe(["data: 0\n\n"]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('every event is one chunk, sent when it happens', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse?n=5&ms=100');
        $times         = [];
        for ($i = 0; $i < 5; ++$i) {
            expect(native_read_chunk($conn))->toBe("data: $i\n\n");
            $times[] = microtime(true);
        }
        expect(native_read_chunk($conn))->toBe('');   // the last chunk: the handler returned
        expect($times[4] - $times[0])->toBeGreaterThan(0.3);
        // The connection serves another request
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('fields: data split on every line ending, event, id and retry, comments; CR, LF (and NUL in id) cannot inject a field', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse-fields');
        expect(sse_chunks($conn))->toBe([
            "event: greeting\nid: 7\nretry: 1500\ndata: one\ndata: two\ndata: three\ndata: four\n\n",
            "data: \n\n",
            "event: evil: injected\nid: idbad\ndata: x\n\n",
            ": keep-alive\n\n",
            ": two lines\n\n",
        ]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('Last-Event-ID is what the client sent, or null at the first connection', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse-last');
        expect(sse_chunks($conn))->toBe(["data: null\n\n"]);
        [$conn, $head] = sse_open($addr, '/sse-last', ['Last-Event-ID' => '42']);
        expect(sse_chunks($conn))->toBe(["data: \"42\"\n\n"]);
        [$conn, $head] = sse_open($addr, '/sse-last', ['Last-Event-ID' => '']);
        expect(sse_chunks($conn))->toBe(["data: \"\"\n\n"]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('a handler that sends until it is told otherwise ends when the client leaves, closing or resetting, without an error in the log', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $clients = [];
        for ($i = 0; $i < 6; ++$i) {
            [$clients[], $head] = sse_open($addr, '/sse-live-stream');
        }
        expect(ws_live($addr, 'sse', 6))->toBe(6);
        expect(native_read_chunk($clients[0]))->toBe(": beat\n\n");
        foreach ($clients as $i => $conn) {
            $i % 2 ? ws_reset($conn) : fclose($conn);
        }
        expect(ws_live($addr, 'sse', 0))->toBe(0);
        expect(probe($addr, '/hello'))->toBe('Hello');
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('HTTP/1.0 has no chunks: the stream is delimited by the close', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        [$conn, $head] = sse_open($addr, '/sse?n=3&ms=10', version: '1.0');
        expect($head['status'])->toBe(200);
        expect($head['headers']['content-type'])->toBe('text/event-stream');
        expect($head['headers'])->not->toHaveKey('transfer-encoding');
        expect($head['headers'])->not->toHaveKey('content-length');
        expect(stream_get_contents($conn))->toBe("data: 0\n\ndata: 1\n\ndata: 2\n\n");
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');

test('HEAD gets the head of the stream and no body, send() throws IOException, and the connection serves the next request', function (array $mode) {
    [$process, $addr, $log] = ws_start($mode);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "HEAD /sse-head HTTP/1.1\r\nHost: test\r\n\r\nGET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        $head = native_read_response($conn, true);
        expect($head['status'])->toBe(200);
        expect($head['headers']['content-type'])->toBe('text/event-stream');
        expect(native_read_response($conn)['body'])->toBe('Hello');
        expect(json_decode((string) probe($addr, '/ws-closes')))->toBe([[0, 'IOException']]);
    } finally {
        native_stop($process);
    }
    ws_clean($log);
})->with('modes');
