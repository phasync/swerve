<?php

/*
 * swerve --http serving a Slim 4 application: bodies stream through Slim too.
 */

beforeEach(function () {
    [$this->master, $this->addr] = native_start('slim.php');
});

afterEach(function () {
    native_stop($this->master);
});

test('a request body streams into a Slim route as it arrives', function () {
    $conn  = native_connect($this->addr);
    $start = microtime(true);
    fwrite($conn, "POST /first/5 HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\nhello");

    expect(native_read_response($conn)['body'])->toBe('hello');
    expect(microtime(true) - $start)->toBeLessThan(1.0);
});

test('a chunked request body echoed by Slim streams in both directions at once', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo-stream HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n");
    native_read_head($conn);
    expect(native_read_chunk($conn))->toBe('hello');

    fwrite($conn, "6\r\n world\r\n0\r\n\r\n");
    expect(native_read_chunk($conn))->toBe(' world');
    expect(native_read_chunk($conn))->toBe('');
});

test('a large chunked request body sent in small writes reaches Slim whole', function () {
    $body = random_bytes(3000000);
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /count HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n");
    foreach (str_split($body, 7000) as $chunk) {
        fwrite($conn, dechex(strlen($chunk)) . "\r\n$chunk\r\n");
    }
    fwrite($conn, "0\r\n\r\n");

    expect(native_read_response($conn)['body'])->toBe('3000000:' . md5($body));
});

test('a HEAD response from Slim has no body and no false Content-Length', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "HEAD /hello HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_response($conn, true);

    expect($head['status'])->toBe(200);
    expect($head['headers'])->not->toHaveKey('content-length');
    expect(native_read_response($conn)['body'])->toBe('Hello');
});
