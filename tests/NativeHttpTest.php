<?php

/*
 * swerve --native-http: every worker speaks HTTP/1.1 on the socket itself, no HAProxy.
 */

beforeEach(function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $this->addr = stream_socket_get_name($probe, false);
    fclose($probe);

    $this->master = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/swerve.php', "--native-http={$this->addr}", '--workers=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );
    $deadline = microtime(true) + 10;
    while (null === http_get($this->addr, '/hello')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('swerve did not start serving on ' . $this->addr);
        }
        usleep(50000);
    }
});

afterEach(function () {
    proc_terminate($this->master, SIGINT);
    proc_close($this->master);
});

/**
 * @return resource
 */
function native_connect(string $addr)
{
    $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    stream_set_timeout($conn, 5);

    return $conn;
}

/**
 * Read one HTTP response: status, headers (lower-cased names) and body, by Content-Length
 * or chunked; or null when the connection ended first.
 *
 * @return array{status: int, headers: array<string, string>, body: string}|null
 */
function native_read_response($conn): ?array
{
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $line = fgets($conn);
        if (false === $line) {
            return null;
        }
        $head .= $line;
    }
    $lines   = explode("\r\n", rtrim($head));
    $status  = (int) explode(' ', array_shift($lines))[1];
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value]                   = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
    $body = '';
    if (isset($headers['content-length'])) {
        $length = (int) $headers['content-length'];
        while (strlen($body) < $length) {
            $chunk = fread($conn, $length - strlen($body));
            if (false === $chunk || ('' === $chunk && feof($conn))) {
                break;
            }
            $body .= $chunk;
        }
    } elseif ('chunked' === strtolower($headers['transfer-encoding'] ?? '')) {
        while (true) {
            $size = hexdec(trim(fgets($conn)));
            if (0 === $size) {
                fgets($conn); // the empty line after the last chunk

                break;
            }
            $chunk = '';
            while (strlen($chunk) < $size) {
                $chunk .= fread($conn, $size - strlen($chunk));
            }
            $body .= $chunk;
            fgets($conn); // CRLF after the chunk
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

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
