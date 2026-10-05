<?php

/*
 * What swerve --http takes off the wire of a request, from a raw client, whatever the application
 * is written in (tests/Fixtures/wire.php).
 */

beforeEach(function () {
    [$this->master, $this->addr] = native_start('wire.php', workers: 1);
});

afterEach(function () {
    native_stop($this->master);
});

test('request headers reach the application in lower case, repeated ones as separate values', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /request HTTP/1.1\r\nHost: t\r\nX-Multi: a\r\nX-Other: o\r\nx-multi: b\r\nX-Utf: h\xc3\xa9llo\r\n\r\n");
    $seen = json_decode(native_read_response($conn)['body'], true)['headers'];
    $byLower = array_change_key_case($seen);

    expect($byLower['x-multi'])->toBe(['a', 'b']);
    expect($byLower['x-other'])->toBe(['o']);
    expect($byLower['x-utf'])->toBe(["h\u{e9}llo"]);
    expect($byLower['host'])->toBe(['t']);
    expect(array_keys($seen))->toContain('x-other');
});

test('Connection and Transfer-Encoding values are matched without regard to case', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: Close\r\n\r\n");
    expect(native_read_response($conn)['headers']['connection'])->toBe('close');
    expect(native_closed($conn))->toBeTrue();

    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: CHUNKED\r\n\r\n5\r\nhello\r\n0\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('hello');
});

test('a request target with a fragment, a control character or an unknown form gets 400; OPTIONS * reaches the application', function () {
    foreach (["GET /a#b HTTP/1.1\r\n", "GET /a\x01b HTTP/1.1\r\n", "G@T /hello HTTP/1.1\r\n", "GET * HTTP/1.1\r\n"] as $line) {
        $conn = native_connect($this->addr);
        fwrite($conn, "{$line}Host: t\r\n\r\n");
        expect(native_read_response($conn)['status'] ?? null)->toBe(400, $line);
        expect(native_closed($conn))->toBeTrue($line);
    }

    $conn = native_connect($this->addr);
    fwrite($conn, "OPTIONS * HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(404); // the application's: it saw the request
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('a request with 100 header fields is accepted, one with 101 gets 431', function () {
    foreach ([99 => 200, 100 => 431] as $extra => $status) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n" . str_repeat("X-A: 1\r\n", $extra) . "\r\n");
        expect(native_read_response($conn)['status'])->toBe($status, "$extra extra");
    }
});

test('100 Continue goes to HTTP/1.1 requests whose body the application reads, chunked ones too, and to no one else', function () {
    // Chunked: sent once the application reads the body, not before it
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\nExpect: 100-Continue\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(100);
    fwrite($conn, "5\r\nhello\r\n0\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['status'], $response['body']])->toBe([200, 'hello']);

    // An empty body, and HTTP/1.0, have nothing to wait for
    foreach (["POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 0\r\nExpect: 100-continue\r\n\r\n", "POST /echo HTTP/1.0\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\nhello"] as $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['status'])->toBe(200, $request);
    }
});

test('a client leaving in the middle of a request body fails the application\'s read, and disturbs nobody', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /slurp HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\n0123456789");
    usleep(100000);
    fclose($conn);

    for ($i = 0; $i < 50 && 'never' === ($result = http_get($this->addr, '/slurped')); ++$i) {
        usleep(100000);
    }
    expect($result)->toBe('failed');
    expect(http_get($this->addr, '/hello'))->toBe('Hello');

    // The same for a chunked body
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /slurp HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n10\r\nnot all");
    usleep(100000);
    fclose($conn);
    for ($i = 0; $i < 50 && 'failed' !== ($result = http_get($this->addr, '/slurped')); ++$i) {
        usleep(100000);
    }
    expect($result)->toBe('failed');
});
