<?php

/*
 * swerve --http --buffer-responses: each response body is read whole, then sent in one write.
 */

beforeEach(function () {
    [$this->master, $this->addr] = native_start('app.php', ['--buffer-responses']);
});

afterEach(function () {
    native_stop($this->master);
});

test('a streamed response is read whole and sent with a Content-Length', function () {
    $conn  = native_connect($this->addr);
    $start = microtime(true);
    fwrite($conn, "GET /stream?n=3&ms=100 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect(microtime(true) - $start)->toBeGreaterThan(0.2); // the head waited for the whole body
    expect($response['headers'])->not->toHaveKey('transfer-encoding');
    expect([$response['headers']['content-length'], $response['body']])->toBe(['30', "piece 000\npiece 001\npiece 002\n"]);
});

test('an HTTP/1.0 connection stays alive with a buffered unknown-size body', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /stream?n=3 HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['headers']['connection'], $response['body']])->toBe(['keep-alive', "piece 000\npiece 001\npiece 002\n"]);

    fwrite($conn, "GET /hello HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('a plain response works buffered', function () {
    expect(http_get($this->addr, '/hello'))->toBe('Hello');
});
