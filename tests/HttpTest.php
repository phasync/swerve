<?php

/*
 * swerve --http: every worker speaks HTTP/1.1 on the socket itself.
 */

use Swerve\ClientRequest;
use Swerve\Http\HttpConnection;

beforeEach(function () {
    [$this->master, $this->addr] = native_start();
});

afterEach(function () {
    native_stop($this->master);
});

test('a GET request gets a response with Content-Length', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
    $response = native_read_response($conn);

    expect([$response['status'], $response['body'], $response['headers']['content-length']])->toBe([200, 'Hello', '5']);
});

test('a keep-alive connection serves one request after another', function () {
    $conn = native_connect($this->addr);
    foreach ([1, 2, 3] as $i) {
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: test\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
    }
});

test('pipelined requests sent in one write get their responses in order', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /sleep?ms=100&id=1 HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\nGET /sleep?ms=10&id=3 HTTP/1.1\r\nHost: t\r\n\r\n");

    expect(array_map(fn () => native_read_response($conn)['body'], [1, 2, 3]))->toBe(['slept 1', 'Hello', 'slept 3']);
});

test('a POST body with Content-Length reaches the application', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 11\r\n\r\nposted data");

    expect(native_read_response($conn)['body'])->toBe('posted data');
});

test('a large POST body reaches the application whole', function () {
    $body = str_repeat('0123456789abcdef', 12500); // 200 000 bytes
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");

    expect(native_read_response($conn)['body'])->toBe($body);
});

test('a chunked POST body reaches the application', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n");

    expect(native_read_response($conn)['body'])->toBe('hello world');
});

test('a body the application does not read is skipped before the next request', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 20\r\n\r\nnot read by the app!GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");

    expect([native_read_response($conn)['body'], native_read_response($conn)['body']])->toBe(['Hello', 'Hello']);
});

test('the method, request target and headers reach the application', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "PUT /params?a=1 HTTP/1.1\r\nHost: t\r\nX-Custom: yes\r\nAccept: text/plain\r\n\r\n");
    $seen = json_decode(native_read_response($conn)['body'], true);

    expect([$seen['method'], $seen['target'], $seen['headers']['x-custom'] ?? null, $seen['headers']['accept'] ?? null])
        ->toBe(['PUT', '/params?a=1', 'yes', 'text/plain']);
});

test('a response larger than 64 KB arrives whole', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /big?n=200000 HTTP/1.1\r\nHost: t\r\n\r\n");

    expect(native_read_response($conn)['body'])->toBe(str_repeat('x', 200000));
});

test('an HTTP/1.0 request, or Connection: close, ends the connection after the response', function () {
    foreach (["GET /hello HTTP/1.0\r\n\r\n", "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n"] as $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['body'])->toBe('Hello');
        expect(native_read_response($conn))->toBeNull();
    }
});

test('a request head larger than the limit gets 431 and the connection ends', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nX-Big: " . str_repeat('a', 70000) . "\r\n\r\n");

    expect(native_read_response($conn)['status'])->toBe(431);
    expect(native_read_response($conn))->toBeNull();
});

test('a malformed request gets 400 and the connection ends', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "NONSENSE\r\n\r\n");

    expect(native_read_response($conn)['status'])->toBe(400);
    expect(native_read_response($conn))->toBeNull();
});

test('requests on different connections are handled concurrently', function () {
    $conns = [];
    for ($i = 1; $i <= 20; ++$i) {
        $conns[$i] = native_connect($this->addr);
        fwrite($conns[$i], "GET /sleep?ms=300&id=$i HTTP/1.1\r\nHost: t\r\n\r\n");
    }
    $start  = microtime(true);
    $bodies = array_map(fn ($c) => native_read_response($c)['body'], $conns);

    expect($bodies)->toBe(array_combine(range(1, 20), array_map(fn ($i) => "slept $i", range(1, 20))));
    expect(microtime(true) - $start)->toBeLessThan(1.0); // 20 x 0.3 s one after another would be 6 s
});

/*
 * Streaming: nothing is buffered, in either direction.
 */

test('a response body of unknown size streams chunked, each piece as it is produced', function () {
    $conn  = native_connect($this->addr);
    $start = microtime(true);
    fwrite($conn, "GET /stream?n=3&ms=300 HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_head($conn);
    expect(native_read_chunk($conn))->toBe("piece 000\n");
    $firstAt = microtime(true) - $start;
    $rest    = native_read_chunk($conn) . native_read_chunk($conn);
    $endAt   = microtime(true) - $start;

    expect($head['headers']['transfer-encoding'])->toBe('chunked');
    expect($head['headers'])->not->toHaveKey('content-length');
    expect($rest)->toBe("piece 001\npiece 002\n");
    expect(native_read_chunk($conn))->toBe(''); // the last chunk
    expect($firstAt)->toBeLessThan(0.25);
    expect($endAt)->toBeGreaterThan(0.55);
});

test('a response body of known size streams with Content-Length, never read whole', function () {
    $conn  = native_connect($this->addr);
    $start = microtime(true);
    fwrite($conn, "GET /stream?n=3&ms=300&size=1 HTTP/1.1\r\nHost: t\r\n\r\n");
    $head    = native_read_head($conn);
    $first   = fread($conn, 10);
    $firstAt = microtime(true) - $start;

    expect([$head['status'], $head['headers']['content-length'], $first])->toBe([200, '30', "piece 000\n"]);
    expect($firstAt)->toBeLessThan(0.25);
    expect(stream_get_contents($conn, 20))->toBe("piece 001\npiece 002\n");
});

test('a body of unknown size is chunked, also when it ends in its first write', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /stream?n=1 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect([$response['headers']['content-length'] ?? null, $response['headers']['transfer-encoding'] ?? null, $response['body']])
        ->toBe([null, 'chunked', "piece 000\n"]);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('a large response body with a known size streams whole', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /big?n=200000 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect([$response['headers']['content-length'], strlen($response['body']), $response['complete']])->toBe(['200000', 200000, true]);
});

test('a Content-Length request body streams to the application as it arrives', function () {
    $conn  = native_connect($this->addr);
    $start = microtime(true);
    fwrite($conn, "POST /first?n=5 HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\nhello");
    $response = native_read_response($conn);

    expect($response['body'])->toBe('hello');
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    expect(native_closed($conn))->toBeTrue(); // the unread rest is too large to skip
});

test('a chunked request body echoed as the response streams in both directions at once', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo-stream HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n");
    $head = native_read_head($conn);
    expect($head['headers']['transfer-encoding'])->toBe('chunked');
    expect(native_read_chunk($conn))->toBe('hello'); // before the client sent the rest

    fwrite($conn, "6\r\n world\r\n0\r\n\r\n");
    expect(native_read_chunk($conn))->toBe(' world');
    expect(native_read_chunk($conn))->toBe('');
});

test('a Content-Length request body echoed as the response arrives whole', function () {
    $body = str_repeat('0123456789abcdef', 12500);
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo-stream HTTP/1.1\r\nHost: t\r\nContent-Length: 200000\r\n\r\n$body");
    $response = native_read_response($conn);

    expect($response['headers']['content-length'])->toBe('200000');
    expect($response['body'] === $body)->toBeTrue();
});

test('chunk extensions and trailer fields are accepted and ignored', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=1\r\nhello\r\n0\r\nX-T: 1\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");

    expect([native_read_response($conn)['body'], native_read_response($conn)['body']])->toBe(['hello', 'Hello']);
});

/*
 * Refused requests: an error status, then the connection closes. The request pipelined behind
 * each one is never answered, so a smuggled request can't get through.
 */

test('requests that could frame their body two ways get 400', function () {
    $cases = [
        'CL and TE'        => "Content-Length: 5\r\nTransfer-Encoding: chunked",
        'two equal CLs'    => "Content-Length: 5\r\nContent-Length: 5",
        'two CLs'          => "Content-Length: 5\r\nContent-Length: 6",
        'CL +5'            => 'Content-Length: +5',
        'CL -1'            => 'Content-Length: -1',
        'CL hex'           => 'Content-Length: 0x10',
        'CL exponent'      => 'Content-Length: 1e3',
        'CL list'          => 'Content-Length: 5, 5',
        'CL empty'         => 'Content-Length:',
        'CL overflow'      => 'Content-Length: ' . str_repeat('9', 20),
        'two TEs'          => "Transfer-Encoding: chunked\r\nTransfer-Encoding: chunked",
        'space before :'   => 'Transfer-Encoding : chunked',
        'obs-fold'         => "X-A: 1\r\n folded",
        'Host space'       => 'X-B: 1',
        'NUL in value'     => "X-A: a\0b",
        'bare LF in value' => "X-A: a\nb",
    ];
    foreach ($cases as $case => $headers) {
        $host = 'Host space' === $case ? 'Host : t' : 'Host: t';
        $conn = native_connect($this->addr);
        fwrite($conn, "POST /echo HTTP/1.1\r\n$host\r\n$headers\r\n\r\n5\r\nhello\r\n0\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['status'] ?? null)->toBe(400, $case);
        expect(native_closed($conn))->toBeTrue($case);
    }
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.0\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(400, 'TE on HTTP/1.0');
    expect(native_closed($conn))->toBeTrue();
});

test('transfer codings other than chunked, and CONNECT, get 501', function () {
    foreach (["POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: gzip, chunked\r\n\r\n", "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: identity\r\n\r\n", "CONNECT a:443 HTTP/1.1\r\nHost: a:443\r\n\r\n"] as $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['status'])->toBe(501, $request);
        expect(native_closed($conn))->toBeTrue();
    }
});

test('malformed chunked bodies get 400', function () {
    $cases = ["zz\r\nhello\r\n0\r\n\r\n", "-5\r\nhello\r\n0\r\n\r\n", "0x5\r\nhello\r\n0\r\n\r\n", "5 x\r\nhello\r\n0\r\n\r\n",
        "\r\nhello\r\n0\r\n\r\n", str_repeat('0', 15) . "5\r\nhello\r\n0\r\n\r\n", "5\r\nhelloXX\r\n0\r\n\r\n", str_repeat('0', 5000) . "5\r\nhello\r\n0\r\n\r\n"];
    foreach ($cases as $body) {
        $conn = native_connect($this->addr);
        fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n{$body}GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['status'] ?? null)->toBe(400, substr($body, 0, 20));
        expect(native_closed($conn))->toBeTrue();
    }
});

test('request heads beyond the limits or not HTTP/1.x get their error status', function () {
    $cases = [
        431 => "GET /hello HTTP/1.1\r\nHost: t\r\n" . str_repeat("X-A: 1\r\n", 100) . "\r\n",
        414 => 'GET /' . str_repeat('a', 9000) . " HTTP/1.1\r\nHost: t\r\n\r\n",
        505 => "GET /hello HTTP/2.0\r\nHost: t\r\n\r\n",
    ];
    foreach ($cases as $status => $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['status'])->toBe($status);
        expect(native_closed($conn))->toBeTrue();
    }
    foreach (["GET / HTTP/1.1 x\r\nHost: t\r\n\r\n", "get / http/1.1\r\nHost: t\r\n\r\n", "GET /hello HTTP/1.1\r\n\r\n",
        "GET /hello HTTP/1.1\r\nHost: a\r\nHost: b\r\n\r\n", "GET foo HTTP/1.1\r\nHost: t\r\n\r\n"] as $request) {
        $conn = native_connect($this->addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['status'])->toBe(400, $request);
        expect(native_closed($conn))->toBeTrue();
    }
    $conn = native_connect($this->addr);
    fwrite($conn, "\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('a slow request head gets 408, an idle new connection closes, an idle kept-alive one closes silently sooner', function () {
    $a = native_connect($this->addr);
    $b = native_connect($this->addr);
    $c = native_connect($this->addr);
    fwrite($c, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($c)['body'])->toBe('Hello');
    $start = microtime(true);
    fwrite($a, "GET /hello HTTP/1.1\r\n");
    stream_set_blocking($a, false);
    stream_set_blocking($b, false);
    stream_set_blocking($c, false);
    $got  = ['a' => '', 'b' => '', 'c' => ''];
    $done = [];
    $sent = 1;
    while (count($done) < 3 && microtime(true) - $start < 14) {
        foreach (['a' => $a, 'b' => $b, 'c' => $c] as $k => $conn) {
            if (isset($done[$k])) {
                continue;
            }
            $data = fread($conn, 8192);
            if (false === $data || ('' === $data && feof($conn))) {
                $done[$k] = microtime(true) - $start;
            } else {
                $got[$k] .= $data;
            }
        }
        if (!isset($done['a']) && '' === $got['a'] && microtime(true) - $start >= $sent) {
            @fwrite($a, 'X'); // one byte of a header line every second
            ++$sent;
        }
        usleep(50000);
    }
    expect(substr($got['a'], 0, 12))->toBe('HTTP/1.1 408');
    expect($done['a'])->toBeGreaterThan(9.5)->toBeLessThan(12.0);
    expect($got['b'])->toBe('');
    expect($done['b'])->toBeGreaterThan(9.5)->toBeLessThan(12.0);
    expect($got['c'])->toBe(''); // KEEP_ALIVE_TIMEOUT: a client expects an idle kept-alive connection to close
    expect($done['c'])->toBeGreaterThan(4.5)->toBeLessThan(6.5);
});

/*
 * Responses without a body.
 */

test('a HEAD response has headers but no body', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "HEAD /hello HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_response($conn, true);
    expect([$head['status'], $head['headers']['content-length']])->toBe([200, '5']);
    expect(native_read_response($conn)['body'])->toBe('Hello');

    fwrite($conn, "HEAD /stream?n=3 HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $head = native_read_response($conn, true);
    expect($head['headers'])->not->toHaveKey('content-length')->not->toHaveKey('transfer-encoding');
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('204 and 304 responses have no body', function () {
    $conn = native_connect($this->addr);
    foreach ([204, 304] as $code) {
        fwrite($conn, "GET /status?code=$code HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect($response['status'])->toBe($code);
        expect($response['headers'])->not->toHaveKey('content-length')->not->toHaveKey('transfer-encoding');
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello'); // nothing was sent after the head
    }
});

test('HTTP/1.0 keeps the connection alive when asked, and ends an unknown-size body at the close', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['body'], $response['headers']['connection']])->toBe(['Hello', 'keep-alive']);
    fwrite($conn, "GET /hello HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');

    $conn = native_connect($this->addr);
    fwrite($conn, "GET /stream?n=3 HTTP/1.0\r\nConnection: keep-alive\r\n\r\n");
    $response = native_read_response($conn);
    expect($response['headers'])->not->toHaveKey('transfer-encoding');
    expect([$response['headers']['connection'], $response['body']])->toBe(['close', "piece 000\npiece 001\npiece 002\n"]);
});

test('Expect: 100-continue is answered when the application reads the body', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");
    $start = microtime(true);
    expect(native_read_response($conn)['status'])->toBe(100);
    expect(microtime(true) - $start)->toBeLessThan(1.0);
    fwrite($conn, 'hello');
    $response = native_read_response($conn);
    expect([$response['status'], $response['body']])->toBe([200, 'hello']);

    // Not read: no 100, and the connection closes since the body may never come
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['status'], $response['headers']['connection']])->toBe([200, 'close']);
    expect(native_closed($conn))->toBeTrue();

    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nExpect: foo\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(417);
});

/*
 * Application failures.
 */

test('an application exception gets 500 before the head is sent, and truncation after', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /throw HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(500);
    expect(native_closed($conn))->toBeTrue();

    $conn = native_connect($this->addr);
    fwrite($conn, "GET /stream?n=3&throw=1 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['status'], $response['body'], $response['complete']])->toBe([200, "piece 000\npiece 001\n", false]);

    expect(http_get($this->addr, '/hello'))->toBe('Hello');
});

test('CR or LF in a response header or reason phrase gets 500, not injected headers', function () {
    foreach (['reason', 'value', 'name'] as $kind) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET /bad?kind=$kind HTTP/1.1\r\nHost: t\r\n\r\n");
        $raw = stream_get_contents($conn);
        expect(substr($raw, 0, 12))->toBe('HTTP/1.1 500', $kind);
        expect($raw)->not->toContain('X-Evil');
    }
});

test('a response body is never sent beyond its declared length, and a short one closes', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /applength?cl=30&actual=3 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['headers']['content-length'], strlen($response['body'])])->toBe(['30', 30]);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');

    $conn = native_connect($this->addr);
    fwrite($conn, "GET /applength?cl=30&actual=2 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);
    expect([strlen($response['body']), $response['complete']])->toBe([20, false]);

    $conn = native_connect($this->addr);
    fwrite($conn, "GET /applength?cl=10&actual=3 HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe("piece 000\n");
    expect(native_closed($conn))->toBeTrue(); // the second write was a bug of the handler's: the connection is aborted
});

test('a client closing mid-response does not disturb the server', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /big?n=50000000 HTTP/1.1\r\nHost: t\r\n\r\n");
    fread($conn, 1024);
    fclose($conn);

    $start = microtime(true);
    expect(http_get($this->addr, '/hello'))->toBe('Hello');
    expect(microtime(true) - $start)->toBeLessThan(1.0);
});

test('a large unread request body closes the connection after the response', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 2000000\r\n\r\n" . str_repeat('x', 2000000));

    expect(native_read_response($conn)['body'])->toBe('Hello');
    expect(native_closed($conn))->toBeTrue();
});

test('200 pipelined requests in one write get their responses in order', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, str_repeat("GET /hello HTTP/1.1\r\nHost: t\r\n\r\n", 200));
    $bodies = [];
    for ($i = 0; $i < 200; ++$i) {
        $bodies[] = native_read_response($conn)['body'] ?? null;
    }

    expect($bodies)->toBe(array_fill(0, 200, 'Hello'));
});

test('a header value with a control character is rejected with 400 (handleRequest\'s own check, not the PSR-7 implementation\'s)', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nX-A: a\x01b\r\n\r\n");

    expect(native_read_response($conn)['status'])->toBe(400);
});

/*
 * Hardening: limits, framing and the request URI.
 */

test('a flood of idle connections neither kills a worker nor drops the connections it serves', function () {
    $kept = native_connect($this->addr);
    fwrite($kept, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($kept)['body'])->toBe('Hello');

    // Well over FD_SETSIZE (1024) connections for each of the two workers
    $flood = [];
    for ($i = 0; $i < 2400; ++$i) {
        $flood[] = stream_socket_client("tcp://{$this->addr}", $errno, $errstr, 5);
    }
    usleep(500000);
    fwrite($kept, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($kept)['body'] ?? null)->toBe('Hello');

    array_map(fclose(...), $flood);
    for ($i = 0; $i < 10; ++$i) {
        expect(http_get($this->addr, '/hello'))->toBe('Hello');
    }
});

test('a request body larger than the limit gets 413, by its Content-Length or its chunks', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 100000000\r\n\r\n");
    expect(native_read_response($conn)['status'] ?? null)->toBe(413);
    expect(native_closed($conn))->toBeTrue();

    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n8000\r\n" . str_repeat('x', 0x8000) . "\r\n" . dechex(8 * 1024 * 1024) . "\r\n");
    expect(native_read_response($conn)['status'] ?? null)->toBe(413);
    expect(native_closed($conn))->toBeTrue();

    [$master, $addr] = native_start('app.php', ['--max-body=10']);
    try {
        foreach (['0123456789' => 200, '0123456789a' => 413] as $body => $status) {
            $conn = native_connect($addr);
            fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
            expect(native_read_response($conn)['status'])->toBe($status);
        }
    } finally {
        native_stop($master);
    }
});

test('skipping an unread request body has a deadline, however slowly the client sends it', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 60000\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');

    stream_set_blocking($conn, false);
    $start    = microtime(true);
    $closedAt = null;
    while (null === $closedAt && microtime(true) - $start < 12) {
        @fwrite($conn, 'x'); // one byte of the body every half second
        usleep(500000);
        $data = @fread($conn, 1);
        if (false === $data || ('' === $data && feof($conn))) {
            $closedAt = microtime(true) - $start;
        }
    }
    expect($closedAt)->not->toBeNull();
    expect($closedAt)->toBeLessThan(7.0);
});

test('the request URI is built from the Host header and a target that is always a path', function () {
    $seen = function (string $head) {
        $conn = native_connect($this->addr);
        fwrite($conn, "$head\r\n\r\n");
        $response = native_read_response($conn);

        return 200 === $response['status'] ? json_decode($response['body'], true) : [$response['status'], $response['body']];
    };

    $params = $seen("GET /params?a=1 HTTP/1.1\r\nHost: good.example:8080");
    expect([$params['uri'], $params['target'], $params['host']])->toBe(['http://good.example:8080/params?a=1', '/params?a=1', 'good.example:8080']);

    // Absolute form: its host wins over the Host header (RFC 9112 3.2.2)
    $params = $seen("GET http://other.example/params HTTP/1.1\r\nHost: good.example");
    expect([$params['uri'], $params['host']])->toBe(['http://other.example/params', 'other.example']);

    $params = $seen('GET /params HTTP/1.0');
    expect($params['uri'])->toBe('/params');

    // One connection, one request after another: each gets its own URI
    $conn = native_connect($this->addr);
    foreach (['/params?a=1' => 'h1', '/params?a=1 ' => 'h2', '/params?a=2' => 'h2'] as $target => $host) {
        fwrite($conn, 'GET ' . trim($target) . " HTTP/1.1\r\nHost: $host\r\n\r\n");
        expect(json_decode(native_read_response($conn)['body'], true)['uri'])->toBe("http://$host" . trim($target));
    }

    // Empty segments are part of the path, never an authority
    expect($seen("GET //evil.example/params HTTP/1.1\r\nHost: good"))->toBe([404, 'Not found: http://good//evil.example/params']);
    expect($seen("GET ///x HTTP/1.1\r\nHost: good"))->toBe([404, 'Not found: http://good///x']);
    expect($seen("GET /x:80 HTTP/1.1\r\nHost: good"))->toBe([404, 'Not found: http://good/x:80']);

    foreach (["GET /params#x HTTP/1.1\r\nHost: good", "GET /params HTTP/1.1\r\nHost: a/b", "GET /params HTTP/1.1\r\nHost: a@b",
        "GET /params HTTP/1.1\r\nHost: a b"] as $head) {
        expect($seen($head)[0])->toBe(400, $head);
    }
});

test('cookies reach the application as cookie parameters', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /params HTTP/1.1\r\nHost: t\r\nCookie: sid=abc; b=x%20y; sid=second\r\n\r\n");

    expect(json_decode(native_read_response($conn)['body'], true)['cookies'])->toBe(['sid' => 'abc', 'b' => 'x y']);
});

test('a response body whose read() has nothing yet does not stall the worker', function () {
    // The data comes from another coroutine in the same worker: a busy loop never lets it run
    foreach (['/nonblocking', '/nonblocking?cl=9'] as $path) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['body'] ?? null, $response['complete'] ?? null])->toBe(['late data', true], $path);
    }
});

test('a response body whose size is 0 but that has data is sent whole', function () {
    foreach (['/pipe' => '/^from-pipe\n\z/', '/proc' => '/^\d+ \(/'] as $path => $pattern) { // /proc/self/stat: the pid, then (the command)
        $conn = native_connect($this->addr);
        fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['complete']])->toBe([200, true]);
        expect($response['body'])->toMatch($pattern);
    }
});

test('closing after a Connection: close response sends no reset, even with a request pipelined behind it', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /sleep?ms=300&id=1 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    usleep(100000);
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    usleep(500000);

    expect(native_read_response($conn)['body'])->toBe('slept 1');
    expect(fread($conn, 1))->toBe(''); // the end of the connection; false is a reset
});

test('a response header name that is not a token gets 500', function () {
    foreach (['Content-Length%20', '%20Folded', '', 'X%3AY', 'X%20Y'] as $name) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET /bad?kind=token&name=$name HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(500, $name);
    }
});

test('the request body is read from the socket in reads of up to 64 KiB, not 8 KiB', function () {
    $body = str_repeat('x', 1000000);
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /read-sizes HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\n$body");

    expect((int) native_read_response($conn)['body'])->toBeGreaterThan(8192);
});

test('a large file-backed response body goes out in writes of up to 64 KiB, not 8 KiB', function () {
    $handler = function (ClientRequest $r) {
        // Forced to disk from the start (php://temp reads 8 KiB at a time by default)
        $fp = fopen('php://temp/maxmemory:0', 'r+');
        fwrite($fp, str_repeat('x', 3000000));
        rewind($fp);
        $r->sendFile($fp);
    };
    $packets = native_serve_packets($handler, "GET / HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    expect(strlen(implode('', $packets)))->toBeGreaterThan(3000000);
    expect(count($packets))->toBeLessThan(50); // 3000000 / 65536 = 46
});

test('a response body that returns more than asked for closes the connection, never desyncs it', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /applength?cl=15&actual=3 HTTP/1.1\r\nHost: t\r\n\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);

    expect([$response['body'], $response['complete']])->toBe(["piece 000\n", false]);
    expect(native_read_response($conn))->toBeNull();
});

test('a request body sent after 100 Continue and echoed as the response keeps the connection', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo-stream HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(100);
    fwrite($conn, 'hello');
    $response = native_read_response($conn);
    expect([$response['body'], $response['headers']['connection'] ?? null])->toBe(['hello', null]);

    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello');
});

test('file descriptors the application holds while streaming never make a connection unselectable', function () {
    [$master, $addr] = native_start('app.php', [], 1);
    try {
        $kept = native_connect($addr);
        fwrite($kept, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($kept)['body'])->toBe('Hello');

        // Each response holds its connection and a socket pair for 2 s: 3 descriptors each
        $conns = [];
        for ($i = 0; $i < 360; ++$i) {
            $conns[$i] = native_connect($addr);
            fwrite($conns[$i], "GET /nonblocking?ms=2000 HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        usleep(500000);
        // Accepted now, these would get descriptor numbers above FD_SETSIZE (1024)
        for (; $i < 400; ++$i) {
            $conns[$i] = native_connect($addr);
            fwrite($conns[$i], "GET /nonblocking?ms=2000 HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        $statuses = array_map(fn ($c) => native_read_response($c)['status'] ?? null, $conns);

        // Each got an answer: 200, or 500 where the application ran out of descriptors
        expect(array_values(array_filter($statuses, fn ($s) => 200 !== $s && 500 !== $s)))->toBe([]);
        fwrite($kept, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($kept)['body'] ?? null)->toBe('Hello');
    } finally {
        native_stop($master);
    }
});

test('at the connection limit, idle connections are closed to make room for new ones', function () {
    [$master, $addr] = native_start('app.php', [], 1);
    try {
        // More kept-alive connections than a worker serves at once (512), each idle after a request
        $conns  = [];
        $bodies = [];
        for ($i = 0; $i < 1000; ++$i) {
            $conns[$i] = native_connect($addr);
            fwrite($conns[$i], "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
            $bodies[] = native_read_response($conns[$i])['body'] ?? null;
        }
        expect($bodies)->toBe(array_fill(0, 1000, 'Hello'));

        // Then as many that never send anything
        $silent = [];
        for ($i = 0; $i < 1000; ++$i) {
            $silent[] = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
        }
        usleep(200000);
        $start = microtime(true);
        expect(http_get($addr, '/hello'))->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(2.0);

        // Those that never sent anything went first: the newest kept-alive one still works
        fwrite($conns[999], "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conns[999])['body'] ?? null)->toBe('Hello');
    } finally {
        native_stop($master);
    }
});

test('an absolute-form target gets the Host checks, and its scheme is http on this plain connection', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET http://a\"<b>{x}/params HTTP/1.1\r\nHost: good\r\n\r\n");
    expect(native_read_response($conn)['status'])->toBe(400);

    $cases = [
        'https://bank.example/params?a=1' => ['http://bank.example/params?a=1', 'bank.example'],
        'HTTP://h.example:81/params'      => ['http://h.example:81/params', 'h.example:81'],
    ];
    foreach ($cases as $target => $expected) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET $target HTTP/1.1\r\nHost: x\r\n\r\n");
        $params = json_decode(native_read_response($conn)['body'], true);
        expect([$params['uri'], $params['host']])->toBe($expected, $target);
    }
    foreach (['http://u@evil/params', 'http:///params', 'http://', 'http://a b/'] as $target) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET $target HTTP/1.1\r\nHost: x\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(400, $target);
    }
});

test('100 Continue is never sent once the response head is out', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /lazy-echo HTTP/1.1\r\nHost: t\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n");
    $head = native_read_head($conn);
    expect([$head['status'], $head['headers']['transfer-encoding']])->toBe([200, 'chunked']);
    expect(native_read_chunk($conn))->toBe("prefix\n");
    usleep(300000);
    fwrite($conn, 'hello');
    expect(native_read_chunk($conn))->toBe('hello');
    expect(native_read_chunk($conn))->toBe('');
});

test('an empty line after a request body leaves the kept-alive connection idle: it closes silently after KEEP_ALIVE_TIMEOUT, no 408', function () {
    $conn = native_connect($this->addr);
    stream_set_timeout($conn, 15);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 5\r\n\r\nhello\r\n");
    expect(native_read_response($conn)['body'])->toBe('hello');

    $start = microtime(true);
    $data  = @fread($conn, 8192); // blocks until the server closes
    expect($data)->toBe('');
    expect(feof($conn))->toBeTrue();
    expect(microtime(true) - $start)->toBeGreaterThan(4.0)->toBeLessThan(6.5);
});

test('a response the server closes after (an unread request body too large to skip) says Connection: close', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 2000000\r\n\r\n" . str_repeat('x', 1000));
    $response = native_read_response($conn);

    expect([$response['body'], $response['headers']['connection'] ?? null])->toBe(['Hello', 'close']);
    expect(native_closed($conn))->toBeTrue();
});

/**
 * A handler that reads the request body $size bytes at a time and answers with its length and md5.
 */
function read_in_pieces_handler(int $size): Closure
{
    return function (ClientRequest $r) use ($size) {
        $data = '';
        while ('' !== ($piece = $r->read($size))) {
            $data .= $piece;
        }
        $body = strlen($data) . ' ' . md5($data);
        $r->sendResponseHeaders(200, ['Content-Length' => (string) strlen($body)]);
        $r->write($body);
    };
}

test('a request body read 8 KiB at a time is still read from the socket up to 64 KiB at once', function () {
    // Each packet must be read whole: a read of 8 KiB would lose the rest of the packet
    $body    = str_repeat('0123456789', 6000);
    $packets = native_serve_packets(read_in_pieces_handler(8192), ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 60000\r\n\r\n", $body], true);

    expect(implode('', $packets))->toEndWith("\r\n\r\n60000 " . md5($body));
});

test('the end of a chunk is read from the socket together with what follows it', function () {
    // The chunk's last 5 bytes arrive in one packet with the rest of the body: read whole
    $packets = native_serve_packets(read_in_pieces_handler(65536), ["POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n", "a\r\nhello", "world\r\n3\r\n!!!\r\n0\r\n\r\n"], true);

    expect(implode('', $packets))->toEndWith("\r\n\r\n13 " . md5('helloworld!!!'));
});

test('a partly read request body echoed as the response declares the length it has left', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /partial HTTP/1.1\r\nHost: t\r\nContent-Length: 10\r\n\r\n0123456789");
    $response = native_read_response($conn);
    expect([$response['headers']['content-length'], $response['body'], $response['complete']])->toBe(['7', '3456789', true]);

    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'] ?? null)->toBe('Hello');
});

test('a client leaving mid-body makes the read throw an IOException', function () {
    $caught  = null;
    $handler = function (ClientRequest $r) use (&$caught) {
        try {
            while ('' !== $r->read()) {
            }
        } catch (phasync\IOException $e) {
            $caught = $e::class;
        }
    };
    native_serve_packets($handler, ["POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\n", 'abc'], true);

    expect($caught)->toBe(phasync\IOException::class);
});

test('at the connection limit, connections already answered (lingering, or skipping an unread body) are closed first', function () {
    [$master, $addr] = native_start('app.php', [], 1);
    try {
        // More than a worker serves at once (512), each answered: skipping a body that never
        // comes (5 s), or lingering after Connection: close (2 s), and never closed by the client
        foreach (["POST /hello HTTP/1.1\r\nHost: t\r\nContent-Length: 1000\r\n\r\n", "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n"] as $request) {
            $conns = [];
            for ($i = 0; $i < 1000; ++$i) {
                $conns[$i] = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
                fwrite($conns[$i], $request);
            }
            usleep(200000);
            $start = microtime(true);
            expect(http_get($addr, '/hello'))->toBe('Hello');
            expect(microtime(true) - $start)->toBeLessThan(1.0);
            array_map(fclose(...), $conns);
            usleep(300000);
        }
    } finally {
        native_stop($master);
    }
});

test('at the connection limit, connections waiting for a request body are closed to make room', function () {
    [$master, $addr] = native_start('app.php', [], 1);
    try {
        $conns = [];
        for ($i = 0; $i < 1000; ++$i) {
            $conns[$i] = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
            fwrite($conns[$i], "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\nx");
        }
        usleep(200000);
        $start = microtime(true);
        expect(http_get($addr, '/hello'))->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(1.0);
    } finally {
        native_stop($master);
    }
});

test('a request body sent too slowly is cut off, however often a byte arrives', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "POST /echo HTTP/1.1\r\nHost: t\r\nContent-Length: 1000000\r\n\r\nx");
    stream_set_blocking($conn, false);
    $start    = microtime(true);
    $closedAt = null;
    while (null === $closedAt && microtime(true) - $start < 16) {
        usleep(500000);
        @fwrite($conn, 'x'); // one byte of the body every half second
        $data = @fread($conn, 1);
        if (false === $data || ('' === $data && feof($conn))) {
            $closedAt = microtime(true) - $start;
        }
    }
    expect($closedAt)->not->toBeNull();
    expect($closedAt)->toBeLessThan(13.0);
});

test('pipelined requests already buffered let the worker\'s other coroutines run in between', function () {
    $handler = function (ClientRequest $r) {
        usleep(1000); // 1 ms of work that never suspends
        $r->sendResponseHeaders(200, ['Content-Length' => '2']);
        $r->write('ok');
    };
    $gap = phasync::run(function () use ($handler) {
        [$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($client, false);
        fwrite($client, str_repeat("GET / HTTP/1.1\r\nHost: t\r\n\r\n", 100));
        $done = false;
        $gap  = 0;
        phasync::go(function () use (&$done, &$gap) {
            for ($last = hrtime(true); !$done; $last = $now) {
                phasync::sleep();
                $gap = max($gap, ($now = hrtime(true)) - $last);
            }
        });
        phasync::go((new HttpConnection(new phasync\Net\StreamDuplex($server, '127.0.0.1:1'), $handler, new Psr\Log\NullLogger(), null))->serve(...));
        $received = '';
        while (substr_count($received, 'HTTP/1.1 200') < 100) {
            $received .= fread(phasync::readable($client, 5), 65536);
        }
        $done = true;
        fclose($client);

        return $gap / 1e6;
    });

    expect($gap)->toBeLessThan(30.0); // all 100 back to back would be 100 ms
});

test('a request body the kernel already holds is read without an event-loop wait before every read, yet the worker still yields', function () {
    $ticks   = 0;
    $handler = function (ClientRequest $r) use (&$ticks) {
        $start = $ticks;
        $size  = 0;
        while ('' !== ($piece = $r->read(65536))) {
            $size += strlen($piece);
        }
        $body = "$size " . ($ticks - $start);
        $r->sendResponseHeaders(200, ['Content-Length' => (string) strlen($body)]);
        $r->write($body);
    };
    $body = phasync::run(function () use ($handler, &$ticks) {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $client   = stream_socket_client('tcp://' . stream_socket_get_name($listener, false));
        $server   = stream_socket_accept($listener);
        stream_set_blocking($server, false);
        $data = "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 1048576\r\nConnection: close\r\n\r\n" . str_repeat('x', 1048576);
        expect(fwrite($client, $data))->toBe(strlen($data)); // all in the kernel's buffers
        $done = false;
        phasync::go(function () use (&$done, &$ticks) {
            while (!$done) {
                ++$ticks;
                phasync::sleep();
            }
        });
        phasync::go((new HttpConnection(new phasync\Net\StreamDuplex($server, '127.0.0.1:1'), $handler, new Psr\Log\NullLogger(), null))->serve(...));
        stream_set_blocking($client, false);
        $received = '';
        while (!feof($client)) {
            $received .= fread(phasync::readable($client, 5), 65536);
        }
        $done = true;

        return substr($received, strpos($received, "\r\n\r\n") + 4);
    });
    [$size, $ticks] = explode(' ', $body);

    expect((int) $size)->toBe(1048576);
    expect((int) $ticks)->toBeGreaterThanOrEqual(1)->toBeLessThan(8); // 16 reads of 64 KiB
});

test('a HEAD response to a request whose unread body is too large to skip says Connection: close', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "HEAD /hello HTTP/1.1\r\nHost: a\r\nContent-Length: 100000\r\n\r\n" . str_repeat('x', 100000) . "GET /hello HTTP/1.1\r\nHost: a\r\n\r\n");
    $head = native_read_response($conn, true);

    expect([$head['status'], $head['headers']['connection'] ?? null])->toBe([200, 'close']);
});

test('an HTTP/1.0 request whose Connection options include close is not kept alive', function () {
    foreach (["Connection: keep-alive, close\r\n", "Connection: keep-alive\r\nConnection: close\r\n"] as $headers) {
        $conn = native_connect($this->addr);
        fwrite($conn, "GET /hello HTTP/1.0\r\n$headers\r\n");
        $response = native_read_response($conn);
        expect([$response['body'], $response['headers']['connection'] ?? null])->toBe(['Hello', 'close'], $headers);
        expect(native_closed($conn))->toBeTrue();
    }
});

test('a 205 response has no body, and Content-Length: 0', function () {
    $conn = native_connect($this->addr);
    fwrite($conn, "GET /status?code=205 HTTP/1.1\r\nHost: t\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['status'], $response['headers']['content-length'] ?? null, $response['body']])->toBe([205, '0', '']);

    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'])->toBe('Hello'); // nothing was sent after the head
});

test('a chunked response body is copied once into its chunk framing', function () {
    $peaks   = [];
    $handler = function (ClientRequest $r) use (&$peaks) {
        // Three 64 KiB pieces of unknown size; each write records how much more memory than
        // right before it was in use at most
        $r->sendResponseHeaders(200);
        for ($i = 0; $i < 3; ++$i) {
            $piece = str_repeat(chr(65 + $i), 65536);
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $r->write($piece);
            $peaks[] = memory_get_peak_usage() - $base;
        }
    };
    $packets = native_serve_packets($handler, "GET / HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

    expect(implode('', $packets))->toContain("\r\n\r\n10000\r\n" . str_repeat('A', 65536) . "\r\n10000\r\n");
    expect(count($peaks))->toBe(3);
    // The first write also joins the held head: one more copy. A second copy of the framing would be 128 KiB
    expect(max(array_slice($peaks, 1)))->toBeLessThan(65536 + 16384, implode(', ', $peaks));
});

/**
 * A stream that gives one byte per read, never waits, and keeps what is written to it. Waiting
 * on it in an event loop waits on a socket that is always readable and writable.
 */
final class OneByteStream
{
    public static string $in  = '';
    public static string $out = '';

    /** @var resource|null */
    private static $ready = null;

    public $context;
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return self::$in[$this->position++] ?? '';
    }

    public function stream_write(string $data): int
    {
        self::$out .= $data;

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$in);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /** @return resource */
    public function stream_cast(int $castAs)
    {
        if (null === self::$ready) {
            [self::$ready, $other] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            fwrite($other, 'x'); // never read: readable for good
            self::$keep = $other;
        }

        return self::$ready;
    }

    /** @var resource|null the other end, kept open */
    private static $keep = null;
}

test('a request head arriving a byte at a time takes linear time, not quadratic', function () {
    if (!in_array('one-byte', stream_get_wrappers(), true)) {
        stream_wrapper_register('one-byte', OneByteStream::class);
    }
    $handler = function (ClientRequest $r) {
        $r->sendResponseHeaders(200, ['Content-Length' => '2']);
        $r->write('ok');
    };
    $time = function (int $size) use ($handler): float {
        $best = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; ++$i) {
            OneByteStream::$in  = "GET / HTTP/1.1\r\nHost: t\r\nX-Pad: " . str_repeat('a', $size) . "\r\n\r\n";
            OneByteStream::$out = '';
            $start              = hrtime(true);
            phasync::run(static fn () => (new HttpConnection(new phasync\Net\StreamDuplex(fopen('one-byte://', 'r+'), '127.0.0.1:1'), $handler, new Psr\Log\NullLogger(), null))->serve());
            $best = min($best, hrtime(true) - $start);
            expect(OneByteStream::$out)->toStartWith('HTTP/1.1 200');
        }

        return $best;
    };
    $time(6000); // warm up

    // Ten times the bytes: ten times the reads, but a hundred times the copying if every read
    // copies the whole head so far (which made it about 17 times slower)
    expect($time(60000) / $time(6000))->toBeLessThan(13.0);
});

test('on a unix: address every worker accepts from one socket, a reload brings new workers to it, and stopping removes the file', function () {
    $dir  = temp_path(true);
    $addr = "unix:$dir/s.sock";
    [$process, , $log, $pid] = swerve_start(['--grace=3'], 3, addr: $addr);

    expect(decoct(fileperms("$dir/s.sock") & 0777))->toBe('666');
    $get = fn () => probe($addr, '/pid');
    $seen = [];
    $deadline = microtime(true) + 5;
    while (count($seen) < 3 && microtime(true) < $deadline) {
        $seen[$get()] = true;
    }
    expect($seen)->toHaveCount(3);

    swerve_signal($process, SIGHUP);
    $deadline = microtime(true) + 10;
    $fresh    = [];
    while (microtime(true) < $deadline && count($fresh) < 3) {
        $pid = $get(); // null while the reload restarts swerve
        if (null !== $pid && !isset($seen[$pid])) {
            $fresh[$pid] = true;
        }
    }
    expect($fresh)->toHaveCount(3);

    swerve_signal($process, SIGTERM);
    [$code] = swerve_wait($process, 10);
    expect($code)->toBe(0);
    expect(file_exists("$dir/s.sock"))->toBeFalse();
});

test('on a unix: address the workers drain at once on a shutdown, not at the end of the grace period', function () {
    $dir = temp_path(true);
    [$process] = swerve_start(['--grace=10'], 2, addr: "unix:$dir/s.sock");

    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    [$code] = swerve_wait($process, 15);
    expect($code)->toBe(0);
    expect(microtime(true) - $start)->toBeLessThan(3.0);
});

test('a unix: address replaces a stale socket file, and is refused where something listens or a file is in the way', function () {
    $dir  = temp_path(true);
    $path = "$dir/s.sock";
    $stale = stream_socket_server("unix://$path");
    fclose($stale);
    expect(file_exists($path))->toBeTrue();

    [$process, $addr] = swerve_start([], 1, addr: "unix:$path");
    expect(probe($addr, '/hello'))->toBe('Hello');

    $second = swerve_spawn(["--http=unix:$path", '--workers=1'], 'app.php', out: [2 => ['pipe', 'w']]);
    [$code] = swerve_wait($second, 10);
    expect($code)->toBe(1);
    expect(probe($addr, '/hello'))->toBe('Hello');

    swerve_signal($process, SIGTERM);
    swerve_wait($process, 10);

    file_put_contents($path, 'not a socket');
    $third = swerve_spawn(["--http=unix:$path", '--workers=1'], 'app.php');
    [$code] = swerve_wait($third, 10);
    expect([$code, file_get_contents($path)])->toBe([1, 'not a socket']);
});
