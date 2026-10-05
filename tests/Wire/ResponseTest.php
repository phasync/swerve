<?php

/*
 * What swerve --http puts on the wire in a response, pinned byte for byte from a raw client,
 * whatever the application is written in (tests/Fixtures/wire.php).
 */

beforeEach(function () {
    [$this->master, $this->addr] = native_start('wire.php', workers: 1);
});

afterEach(function () {
    native_stop($this->master);
});

/**
 * The lines of a response head, status line first, or [] when the connection ended first.
 *
 * @return string[]
 */
function wire_raw_head($conn): array
{
    $lines = [];
    while (false !== ($line = fgets($conn)) && "\r\n" !== $line) {
        $lines[] = rtrim($line, "\r\n");
    }

    return $lines;
}

/**
 * Send a request, read the head of the response.
 *
 * @return string[]
 */
function wire_head(string $addr, string $request): array
{
    $conn = native_connect($addr);
    fwrite($conn, $request);

    return wire_raw_head($conn);
}

test('the status line is HTTP/1.1 with the reason phrase, also to an HTTP/1.0 request', function () {
    expect(wire_head($this->addr, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n")[0])->toBe('HTTP/1.1 200 OK');
    expect(wire_head($this->addr, "GET /hello HTTP/1.0\r\n\r\n")[0])->toBe('HTTP/1.1 200 OK');
    expect(wire_head($this->addr, "GET /nothing HTTP/1.1\r\nHost: t\r\n\r\n")[0])->toBe('HTTP/1.1 404 Not Found');
});

test('Date is the current time in IMF-fixdate, once, on every kind of response', function () {
    $requests = [
        "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n",
        "HEAD /hello HTTP/1.1\r\nHost: t\r\n\r\n",
        "GET /status?code=204 HTTP/1.1\r\nHost: t\r\n\r\n",
        "GET /chunked?n=2&size=3 HTTP/1.1\r\nHost: t\r\n\r\n",
    ];
    foreach ($requests as $request) {
        $dates = preg_grep('/^date:/i', wire_head($this->addr, $request));
        expect($dates)->toHaveCount(1, $request);
        $date = substr((string) reset($dates), 6);
        expect($date)->toMatch('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d\d (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d\d:\d\d:\d\d GMT$/');
        expect(abs(strtotime($date) - time()))->toBeLessThan(5);
    }
});

test('a Date header from the application is sent as it is, and no other', function () {
    $dates = preg_grep('/^date:/i', wire_head($this->addr, "GET /date HTTP/1.1\r\nHost: t\r\n\r\n"));

    expect(array_values($dates))->toBe(['Date: Mon, 01 Jan 2001 00:00:00 GMT']);
});

test('an error response from swerve itself carries a Date', function () {
    expect(preg_grep('/^date:/i', wire_head($this->addr, "NONSENSE\r\n\r\n")))->toHaveCount(1);
});

test('an error response from swerve itself has its reason phrase, no body, and says Connection: close', function () {
    $cases = [
        'HTTP/1.1 400 Bad Request'                    => "NONSENSE\r\n\r\n",
        'HTTP/1.1 414 URI Too Long'                   => 'GET /' . str_repeat('a', 9000) . " HTTP/1.1\r\nHost: t\r\n\r\n",
        'HTTP/1.1 431 Request Header Fields Too Large' => "GET /hello HTTP/1.1\r\nHost: t\r\nX-Big: " . str_repeat('a', 70000) . "\r\n\r\n",
        'HTTP/1.1 505 HTTP Version Not Supported'     => "GET /hello HTTP/2.0\r\nHost: t\r\n\r\n",
        'HTTP/1.1 501 Not Implemented'                => "CONNECT a:443 HTTP/1.1\r\nHost: a:443\r\n\r\n",
        'HTTP/1.1 417 Expectation Failed'             => "GET /hello HTTP/1.1\r\nHost: t\r\nExpect: foo\r\n\r\n",
        'HTTP/1.1 413 Content Too Large'              => "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 9000000\r\n\r\n",
    ];
    foreach ($cases as $statusLine => $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        $head = wire_raw_head($conn);
        expect($head[0])->toBe($statusLine);
        expect($head)->toContain('Content-Length: 0')->toContain('Connection: close');
        expect(native_closed($conn))->toBeTrue($statusLine); // nothing after the head, then the end
    }
});

test('response headers are sent under the names the application gave them, one line for each value, in order', function () {
    $head = wire_head($this->addr, "GET /headers HTTP/1.1\r\nHost: t\r\n\r\n");

    expect(array_values(preg_grep('/^(set-cookie|vary|x-mixed-case):/i', $head)))->toBe([
        'Set-Cookie: a=1',
        'Set-Cookie: b=2',
        'X-Mixed-Case: v',
        'Vary: Accept',
        'Vary: Accept-Encoding',
    ]);
});

test('Connection: close from the application closes the connection', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /close HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect([$response['body'], $response['headers']['connection']])->toBe(['x', 'close']);
    expect(native_read_response($conn))->toBeNull(); // the pipelined request is not answered
});

test('Transfer-Encoding and Keep-Alive headers from the application are dropped: swerve decides the framing', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /framing HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect($response['headers'])->not->toHaveKey('transfer-encoding')->not->toHaveKey('keep-alive');
    expect([$response['headers']['content-length'], $response['body']])->toBe(['3', 'abc']);
    expect(native_read_response($conn)['body'])->toBe('Hello'); // and the connection stays alive
});

test('a response body of unknown size is chunked in HTTP/1.1, one chunk for each piece the application produced', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /chunked?n=3&size=4&ms=50 HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_head($conn);

    expect($head['headers'])->not->toHaveKey('content-length');
    expect($head['headers']['transfer-encoding'])->toBe('chunked');
    $chunks = [];
    while ('' !== ($chunk = native_read_chunk($conn))) {
        $chunks[] = $chunk;
    }
    expect($chunks)->toBe(['AAAA', 'BBBB', 'CCCC']);
});

test('a client that sent its request and half-closed still gets the whole response', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /big?n=300000 HTTP/1.1\r\nHost: t\r\n\r\n");
    stream_socket_shutdown($conn, STREAM_SHUT_WR);
    $response = native_read_response($conn);

    expect([$response['status'], strlen($response['body']), $response['complete']])->toBe([200, 300000, true]);
    expect(native_closed($conn))->toBeTrue();
});

test('a large response to a client that reads slowly arrives whole, while the worker serves others', function () {
    foreach (['/big?n=8000000' => 8000000, '/chunked?n=64&size=65536' => 4194304] as $target => $size) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET $target HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($conn);
        usleep(300000); // the socket buffers fill: the server's writes wait for the client
        $start = microtime(true);
        expect(http_get($this->addr, '/hello'))->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(1.0);

        $received = 0;
        $hash     = hash_init('md5');
        while ($received < $size) {
            if (isset($head['headers']['content-length'])) {
                $data = fread($conn, 65536);
            } else {
                $data = native_read_chunk($conn);
            }
            expect($data)->not->toBeFalse()->not->toBeNull();
            hash_update($hash, $data);
            $received += strlen($data);
            usleep(1000);
        }
        $expected = isset($head['headers']['content-length'])
            ? md5(str_repeat('x', $size))
            : md5(implode('', array_map(fn ($i) => str_repeat(chr(65 + $i % 26), 65536), range(0, 63))));
        expect([$received, hash_final($hash)])->toBe([$size, $expected], $target);
    }
});

test('a client leaving in the middle of a response of unknown size ends the application\'s producer and disturbs nobody', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /chunked?n=100000&size=65536 HTTP/1.1\r\nHost: t\r\n\r\n");
    native_read_head($conn);
    fread($conn, 1024);
    expect(http_get($this->addr, '/live'))->toBe('1');
    fclose($conn);

    $start = microtime(true);
    expect(http_get($this->addr, '/hello'))->toBe('Hello');
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    for ($i = 0; $i < 50 && '0' !== http_get($this->addr, '/live'); ++$i) {
        usleep(100000);
    }
    expect(http_get($this->addr, '/live'))->toBe('0');
});
