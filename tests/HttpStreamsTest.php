<?php

/*
 * swerve --http deals in two streams: the request body, which the handler reads from the
 * ClientRequest, and the response body, which it writes to it. Upgrades (101) are nothing more:
 * the rest of the connection in each direction. WebSocket and Server-Sent Events here are
 * applications built on the two streams (see the fixture), not code of swerve's.
 */

use Swerve\ClientRequest;
use Swerve\Http\HttpConnection;

/**
 * A logger keeping its lines ("level: message"), for the in-process tests.
 */
function streams_logger(): Psr\Log\AbstractLogger
{
    return new class extends Psr\Log\AbstractLogger {
        public array $lines = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->lines[] = "$level: $message";
        }
    };
}

/**
 * Serve one connection in this process: $client runs with the client's end of a stream socket
 * pair (with $tcp, a TCP connection), non-blocking. Returns what $client returns, once the
 * server's side has ended too.
 *
 * @param Closure(ClientRequest): void $handler
 */
function serve_in_process(Closure $handler, Closure $client, ?Psr\Log\LoggerInterface $logger = null, bool $tcp = false): mixed
{
    return phasync::run(function () use ($handler, $client, $logger, $tcp) {
        if ($tcp) {
            $listener = stream_socket_server('tcp://127.0.0.1:0');
            $conn     = stream_socket_client('tcp://' . stream_socket_get_name($listener, false));
            $server   = stream_socket_accept($listener);
            fclose($listener);
        } else {
            [$server, $conn] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        }
        stream_set_blocking($server, false);
        stream_set_blocking($conn, false);
        phasync::go((new HttpConnection(new phasync\Net\StreamDuplex($server, '127.0.0.1:1'), $handler, $logger ?? new Psr\Log\NullLogger(), null))->serve(...));
        try {
            return $client($conn);
        } finally {
            fclose($conn);
        }
    });
}

/**
 * Answer a request with $body, declaring its length.
 */
function streams_answer(ClientRequest $r, string $body, int $status = 200): void
{
    $r->sendResponseHeaders($status, ['Content-Length' => (string) strlen($body)]);
    $r->write($body);
}

/**
 * Write all of $data from a coroutine; false when the server closed first.
 */
function client_write($conn, string $data): bool
{
    while ('' !== $data) {
        $n = @fwrite(phasync::writable($conn, 5), $data);
        if (false === $n) {
            return false;
        }
        $data = substr($data, $n);
    }

    return true;
}

/**
 * Everything the server sends until it closes, from a coroutine. A reset instead of a clean
 * end throws.
 */
function client_read_all($conn): string
{
    $data = '';
    while (true) {
        $chunk = fread(phasync::readable($conn, 5), 65536);
        if (false === $chunk) {
            throw new RuntimeException('The connection was reset');
        }
        if ('' === $chunk && feof($conn)) {
            return $data;
        }
        $data .= $chunk;
    }
}

/**
 * The responses in what a connection received.
 *
 * @return array<int, array{status: int, headers: array<string, string>, body: string, complete: bool}>
 */
function parse_responses(string $data): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $data);
    rewind($stream);
    $responses = [];
    while (null !== $response = native_read_response($stream)) {
        $responses[] = $response;
    }

    return $responses;
}

/**
 * Send $send on a blocking connection while reading what comes back, until $expect bytes came
 * back (or the connection ended, or $timeout). $send goes out in pieces of $piece bytes, $pause
 * seconds apart. Returns what came back and how much was sent when its first byte arrived.
 *
 * @return array{0: string, 1: int}
 */
function duplex($conn, string $send, int $expect, int $piece = 65536, float $pause = 0, float $timeout = 10): array
{
    stream_set_blocking($conn, false);
    $received  = '';
    $sent      = 0;
    $sentFirst = null;
    $deadline  = microtime(true) + $timeout;
    $next      = 0.0;
    while (strlen($received) < $expect && microtime(true) < $deadline) {
        $read   = [$conn];
        $write  = $sent < strlen($send) && microtime(true) >= $next ? [$conn] : null;
        $except = null;
        if (!stream_select($read, $write, $except, 0, 10000)) {
            continue;
        }
        if ($write) {
            $n = fwrite($conn, substr($send, $sent, $piece));
            $sent += (int) $n;
            $next = microtime(true) + $pause;
        }
        if ($read) {
            $chunk = @fread($conn, 65536);
            if (false === $chunk || ('' === $chunk && feof($conn))) {
                break;
            }
            $sentFirst ??= '' === $chunk ? null : $sent;
            $received .= $chunk;
        }
    }
    stream_set_blocking($conn, true);

    return [$received, (int) $sentFirst];
}

/*
 * The request body
 */

test('a request body and its response stream at once, both ways (full duplex)', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $data = '';
        for ($i = 0; strlen($data) < 1048576; ++$i) {
            $data .= sprintf("line %07d of the upload\n", $i);
        }
        $data = substr($data, 0, 1048576);
        $conn = native_connect($addr);
        $send = "POST /duplex HTTP/1.1\r\nHost: t\r\nContent-Length: 1048576\r\n\r\n$data" . "GET /hello HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n";
        // In pieces, 5 ms apart: the kernel's buffers alone could take the whole upload at once
        [$received, $sentFirst] = duplex($conn, $send, PHP_INT_MAX, 65536, 0.005, 10);
        $responses              = parse_responses($received);

        expect($sentFirst)->toBeLessThan(strlen($send));
        expect(count($responses))->toBe(2);
        expect($responses[0]['headers']['transfer-encoding'] ?? null)->toBe('chunked');
        expect($responses[0]['body'] === strtoupper($data))->toBeTrue();
        expect($responses[1]['body'])->toBe('Hello');
    } finally {
        native_stop($master);
    }
});

test('a request body the handler leaves unread is skipped, and a pipelined request is answered on the same connection', function () {
    $received = serve_in_process(fn (ClientRequest $r) => streams_answer($r, 'Hello'), function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 10\r\n\r\n0123456789GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });
    $responses = parse_responses($received);

    expect(array_column($responses, 'body'))->toBe(['Hello', 'Hello']);
    expect(array_map(fn ($r) => $r['headers']['connection'] ?? null, $responses))->toBe([null, null]);
});

test('a request body read partly by the handler has its rest skipped', function () {
    $received = serve_in_process(function (ClientRequest $r) {
        $r->read(10);
        streams_answer($r, 'ok');
    }, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 30000\r\n\r\n" . str_repeat('x', 30000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['ok', 'ok']);
});

test('a request body too large to skip closes the connection after the response', function () {
    $received = serve_in_process(fn (ClientRequest $r) => streams_answer($r, 'ok'), function ($conn) {
        $writer   = phasync::go(fn () => client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 200000\r\n\r\n" . str_repeat('x', 200000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n") && stream_socket_shutdown($conn, STREAM_SHUT_WR));
        $received = client_read_all($conn);
        phasync::await($writer);

        return $received;
    });
    $responses = parse_responses($received);

    expect(count($responses))->toBe(1);
    expect($responses[0]['headers']['connection'] ?? null)->toBe('close');
});

test('a malformed chunked body found by the handler\'s read answers 400 and closes the connection', function () {
    $received = serve_in_process(function (ClientRequest $r) {
        while ('' !== $r->read()) {
        }
        streams_answer($r, 'ok');
    }, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\nzz\r\nhello\r\n0\r\n\r\nGET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });

    expect(array_column(parse_responses($received), 'status'))->toBe([400]);
});

/*
 * Upgrade requests that are not switched
 */

test('an upgrade request answered with a normal response is framed as usual, and the connection is kept alive', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $upgrade = "Connection: keep-alive, Upgrade\r\nUpgrade: websocket\r\n";
        $conn    = native_connect($addr);
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n$upgrade\r\nGET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        foreach ([1, 2] as $i) {
            $response = native_read_response($conn);
            expect([$response['status'], $response['body'], $response['headers']['content-length'] ?? null, $response['headers']['upgrade'] ?? null, $response['headers']['connection'] ?? null])
                ->toBe([200, 'Hello', '5', null, null]);
        }

        fwrite($conn, "GET /status?code=426 HTTP/1.1\r\nHost: t\r\n$upgrade\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['headers']['connection'] ?? null])->toBe([426, null]);

        // What follows the upgrade request is the next request
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n$upgrade\r\nGET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
        expect((int) native_read_response($conn)['body'])->toBeGreaterThan(0);
    } finally {
        native_stop($master);
    }
});

test('101 before the upgrade request\'s body was read to its end gets 500', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "POST /upgrade-echo HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: echo\r\nContent-Length: 5\r\n\r\nhello");
        expect(native_read_response($conn)['status'])->toBe(500);
    } finally {
        native_stop($master);
    }
});

test('a client half-closing before the 101 still gets its tunnel: what it sent is the input, which then ends; one that leaves is no error', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    // The reader waits for the response status while handle() takes its time, as an
    // asynchronous authentication would; the client sends its first tunnel bytes at once
    foreach (['hello' => "HELLOEOF\n", '' => "EOF\n"] as $early => $expected) {
        $conn = native_connect($addr);
        fwrite($conn, "GET /upgrade-upper?wait=300 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n$early");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);
        expect(native_read_head($conn)['status'] ?? null)->toBe(101);
        expect(stream_get_contents($conn))->toBe($expected);
        fclose($conn);
    }
    $conn = native_connect($addr);
    fwrite($conn, "GET /upgrade-upper?wait=300 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n");
    usleep(100_000);
    fclose($conn);
    usleep(500_000);

    expect(probe($addr, '/hello'))->toBe('Hello');
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0);
    native_stop($process);
});

test('a non-101 answer to an upgrade request carries the application\'s Upgrade and Connection options, as a 426 must', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /refuse-upgrade HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['headers']['upgrade'] ?? null, $response['headers']['connection'] ?? null, $response['body']])
            ->toBe([426, 'websocket', 'Upgrade', 'upgrade required']);

        // Kept alive; and when the connection closes, both options are said
        fwrite($conn, "GET /refuse-upgrade HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['headers']['upgrade'] ?? null, $response['headers']['connection'] ?? null])->toBe(['websocket', 'Upgrade, close']);
        expect(native_closed($conn))->toBeTrue();
    } finally {
        native_stop($master);
    }
});

test('101 answering a request that is not an HTTP/1.1 upgrade request gets 500', function (string $request) {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, $request);
        expect(native_read_response($conn)['status'])->toBe(500);
    } finally {
        native_stop($master);
    }
})->with([
    'no Upgrade'           => ["GET /status?code=101 HTTP/1.1\r\nHost: t\r\n\r\n"],
    'Upgrade not named'    => ["GET /status?code=101 HTTP/1.1\r\nHost: t\r\nUpgrade: x\r\n\r\n"],
    'HTTP/1.0 with Upgrade' => ["GET /status?code=101 HTTP/1.0\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n"],
]);

/*
 * 101 Switching Protocols
 */

test('a 101 goes out at once, then both directions are the raw connection, beyond the HTTP limits', function () {
    [$master, $addr] = native_start(args: ['--max-body=1000'], workers: 1);
    try {
        $conn  = native_connect($addr);
        $start = microtime(true);
        fwrite($conn, "GET /upgrade-echo HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: echo\r\n\r\n");
        $head = native_read_head($conn);
        expect(microtime(true) - $start)->toBeLessThan(0.2);
        expect($head['status'])->toBe(101);
        expect([$head['headers']['upgrade'] ?? null, $head['headers']['connection'] ?? null])->toBe(['echo', 'Upgrade']);
        expect(array_intersect_key($head['headers'], ['content-length' => 1, 'transfer-encoding' => 1]))->toBe([]);

        fwrite($conn, 'hello');
        expect(read_exactly($conn, 5))->toBe('hello');

        $data         = random_bytes(3 * 102400); // far past --max-body
        [$received]   = duplex($conn, $data, strlen($data));
        expect($received === $data)->toBeTrue();

        fwrite($conn, 'last');
        stream_socket_shutdown($conn, STREAM_SHUT_WR);
        expect(stream_get_contents($conn))->toBe('last');
        expect(feof($conn))->toBeTrue();

        // Bytes sent right behind the head are the tunnel's first
        $conn = native_connect($addr);
        fwrite($conn, "GET /upgrade-echo HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: echo\r\n\r\nearly");
        expect(native_read_head($conn)['status'])->toBe(101);
        expect(read_exactly($conn, 5))->toBe('early');
    } finally {
        native_stop($master);
    }
});

test('a tunnel outlives the HTTP timeouts', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        stream_set_timeout($conn, 20);
        fwrite($conn, "GET /upgrade-upper HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\n\r\n");
        expect(native_read_head($conn)['status'])->toBe(101);
        sleep(11); // past BODY_TIMEOUT and HEAD_TIMEOUT

        fwrite($conn, 'x');
        expect(fread($conn, 10))->toBe('X');
        fwrite($conn, 'q');
        expect(stream_get_contents($conn))->toBe("bye\n");
    } finally {
        native_stop($master);
    }
})->group('slow');

test('a tunnel whose response ended while its input is left unread, or read on, closes without a reset: the client gets all of it', function (string $query) {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /upgrade-hold?kb=1024$query HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n");
        expect(native_read_head($conn)['status'])->toBe(101);
        fwrite($conn, 'client-bytes');
        // Within the 2 s the connection lingers for the client's last bytes after the handler returned
        usleep(1_200_000);
        fwrite($conn, 'more-bytes');
        usleep(2_000_000);
        stream_set_blocking($conn, false);
        [$received, $reset] = read_to_end($conn);

        expect($reset)->toBeFalse();
        expect(strlen($received))->toBe(1024 * 1024 + 3);
        expect(str_ends_with($received, 'END'))->toBeTrue();
    } finally {
        native_stop($master);
    }
})->with([
    'held unread'                           => [''],
    'read on by a coroutine'                => ['&read=1'],
]);

test('closing the request body ends a tunnel, also while its client stopped reading', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $pid                    = (int) probe($addr, '/pid');
    $fds                    = static fn () => count(scandir("/proc/$pid/fd")) - 2;
    $base                   = $fds();
    $conn                   = native_connect($addr);
    fwrite($conn, "GET /upgrade-abort?mb=64&ms=500 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(101);
    // Never read again: the worker's writes stall once the buffers are full

    $deadline = microtime(true) + 3;
    while ($fds() > $base && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($fds())->toBe($base);
    native_stop($process);
});

test('a WebSocket written on the two streams alone works through swerve', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/ws');
        ws_send($conn, 1, 'hello');
        expect(ws_read($conn))->toBe([1, 'hello']);
        $long = str_repeat('0123456789', 7000); // a 64-bit length
        ws_send($conn, 1, $long);
        expect(ws_read($conn) === [1, $long])->toBeTrue();
        ws_send($conn, 9, 'p');
        expect(ws_read($conn))->toBe([10, 'p']);
        ws_send($conn, 8, pack('n', 1000));
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        expect(ws_read($conn))->toBeNull();

        // The server says goodbye first; the client answers, and the connection ends cleanly
        $conn = ws_connect($addr, '/ws?bye=1');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        ws_send($conn, 8, pack('n', 1000));
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors) {
            $errors[] = $message;

            return true;
        });
        try {
            expect(fread($conn, 10))->toBe('');
        } finally {
            restore_error_handler();
        }
        expect([feof($conn), $errors])->toBe([true, []]);
    } finally {
        native_stop($master);
    }
});

test('Server-Sent Events arrive one by one as they are produced, on a connection kept alive', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn  = native_connect($addr);
        $start = microtime(true);
        fwrite($conn, "GET /sse?n=3&ms=200 HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($conn);
        expect([$head['status'], $head['headers']['content-type'], $head['headers']['transfer-encoding'] ?? null])->toBe([200, 'text/event-stream', 'chunked']);
        $times  = [];
        $events = [];
        while ('' !== ($chunk = native_read_chunk($conn))) {
            $times[]  = microtime(true) - $start;
            $events[] = $chunk;
        }
        expect($events)->toBe(["data: 0\n\n", "data: 1\n\n", "data: 2\n\n"]);
        expect($times[0])->toBeLessThan(0.15);
        expect($times[1] - $times[0])->toBeGreaterThan(0.15);
        expect($times[2] - $times[1])->toBeGreaterThan(0.15);

        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
    } finally {
        native_stop($master);
    }
});

test('a Server-Sent Events producer learns its client left, when its stream has a finite deadlock timeout', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conns = [];
        for ($i = 0; $i < 3; ++$i) {
            $conns[$i] = native_connect($addr);
            fwrite($conns[$i], "GET /sse-beat HTTP/1.1\r\nHost: t\r\n\r\n");
            expect(native_read_head($conns[$i])['status'])->toBe(200);
            expect(native_read_chunk($conns[$i]))->toBe(": beat\n\n");
        }
        expect(probe($addr, '/sse-live'))->toBe('3');
        foreach ($conns as $conn) {
            fclose($conn);
        }

        $deadline = microtime(true) + 5;
        while ('0' !== probe($addr, '/sse-live') && microtime(true) < $deadline) {
            usleep(100_000);
        }
        expect(probe($addr, '/sse-live'))->toBe('0');
    } finally {
        native_stop($master);
    }
});

test('many tunnels at once in one worker, which still answers requests meanwhile', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conns                  = [];
    for ($i = 0; $i < 200; ++$i) {
        $conns[$i] = native_connect($addr);
        fwrite($conns[$i], "GET /upgrade-echo HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: echo\r\n\r\n");
    }
    foreach ($conns as $conn) {
        expect(native_read_head($conn)['status'])->toBe(101);
    }
    for ($round = 0; $round < 3; ++$round) {
        foreach ($conns as $i => $conn) {
            fwrite($conn, sprintf("%03d:%d\n", $i, $round));
        }
        $start = microtime(true);
        expect(probe($addr, '/hello'))->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(0.5);
        foreach ($conns as $i => $conn) {
            expect(read_exactly($conn, 6))->toBe(sprintf("%03d:%d\n", $i, $round));
        }
    }
    foreach ($conns as $conn) {
        stream_socket_shutdown($conn, STREAM_SHUT_WR);
    }
    foreach ($conns as $conn) {
        expect(native_closed($conn))->toBeTrue();
        fclose($conn);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0);
    native_stop($process);
});

test('a WebSocket client that just leaves frees its connection, without an error', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $pid                    = (int) probe($addr, '/pid');
    usleep(100_000);
    $fds  = static fn () => count(scandir("/proc/$pid/fd")) - 2;
    $base = $fds();

    $conn = ws_connect($addr, '/ws');
    ws_send($conn, 1, 'hi');
    expect(ws_read($conn))->toBe([1, 'hi']);
    expect($fds())->toBeGreaterThan($base);
    fclose($conn);

    expect(probe($addr, '/hello'))->toBe('Hello');
    $deadline = microtime(true) + 3;
    while ($fds() > $base && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($fds())->toBe($base);
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0);
    native_stop($process);
});

test('a WebSocket client that resets its connection frees it, without an error', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conn                   = ws_connect($addr, '/ws');
    ws_send($conn, 1, 'hi');
    expect(ws_read($conn))->toBe([1, 'hi']);
    $socket = socket_import_stream($conn);
    socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
    socket_close($socket); // a reset, not a clean close
    usleep(300_000);

    expect(probe($addr, '/hello'))->toBe('Hello');
    expect(log_count($log, '/(ERROR|CRITICAL|failed|Unhandled)/i'))->toBe(0);
    native_stop($process);
})->skip(!function_exists('socket_create'), 'the test uses ext-sockets');

test('phasync::finally() in the handler runs after the whole response, streamed too, in the request\'s coroutine, before the next request on the connection', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /finally-stream HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'] ?? null)->toBe('abc');
        fwrite($conn, "GET /finally-log HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(json_decode(native_read_response($conn)['body'] ?? 'null', true))->toBe(['body ended', "finally ran in the request's coroutine"]);
    } finally {
        native_stop($master);
    }
});

test('a request run in a switch-aware context keeps its own static property while requests overlap in one worker', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conns = [];
        foreach (['a', 'b', 'c'] as $v) {
            $conns[$v] = native_connect($addr);
            fwrite($conns[$v], "GET /swap?v=$v&ms=100 HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        foreach ($conns as $v => $conn) {
            expect(native_read_response($conn)['body'] ?? null)->toBe($v);
        }
    } finally {
        native_stop($master);
    }
});

test('each request gets a phasync context of its own, created when its code asks for one, shared by the coroutines it starts', function () {
    $seen    = [];
    $handler = function (ClientRequest $r) use (&$seen) {
        $seen[] = [phasync::getContext(), phasync::await(phasync::go(static fn () => phasync::getContext()))];
        streams_answer($r, 'ok');
    };
    $never = fn (ClientRequest $r) => streams_answer($r, 'none');
    foreach ([$handler, $never] as $h) {
        serve_in_process($h, function ($conn) {
            client_write($conn, "GET /a HTTP/1.1\r\nHost: t\r\n\r\nGET /b HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

            return array_column(parse_responses(client_read_all($conn)), 'status');
        });
    }
    expect($seen)->toHaveCount(2);
    expect($seen[0][0])->toBe($seen[0][1]);
    expect($seen[1][0])->toBe($seen[1][1]);
    expect($seen[0][0])->not->toBe($seen[1][0]);
});

test('the client has the whole response while the request\'s own work goes on, and the connection closes after it', function () {
    $ended   = false;
    $handler = function (ClientRequest $r) use (&$ended) {
        phasync::go(static function () use (&$ended) {
            phasync::sleep(0.2);
            $ended = true;
        });
        streams_answer($r, 'ok');
    };
    [$response, $endedWhenRead] = serve_in_process($handler, function ($conn) use (&$ended) {
        client_write($conn, "GET / HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");

        return [client_read_all($conn), $ended]; // reads until the close: the work has not ended
    });
    expect($response)->toContain('ok');
    expect($endedWhenRead)->toBeFalse();
    expect($ended)->toBeTrue();
});
