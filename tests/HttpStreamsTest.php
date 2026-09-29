<?php

/*
 * swerve --http deals in two streams: the request body, still connected to the socket, which
 * the application may read whenever and from wherever it likes, also after the response; and
 * the response body, which swerve reads and delivers. Upgrades (101) are nothing more: the rest
 * of the connection in each direction. WebSocket and Server-Sent Events here are applications
 * built on the two streams (see the fixture), not code of swerve's.
 */

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\NativeHttpConnection;

/**
 * A handler of the given closure, for the in-process tests.
 */
function streams_handler(Closure $handle): RequestHandlerInterface
{
    return new class($handle) implements RequestHandlerInterface {
        public function __construct(private Closure $handle)
        {
        }

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return ($this->handle)($request);
        }
    };
}

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
 */
function serve_in_process(RequestHandlerInterface $handler, Closure $client, ?Psr\Log\LoggerInterface $logger = null, bool $tcp = false): mixed
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
        phasync::go((new NativeHttpConnection($server, '127.0.0.1:1', $handler, $logger ?? new Psr\Log\NullLogger()))->serve(...));
        try {
            return $client($conn);
        } finally {
            fclose($conn);
        }
    });
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
 * The request body after the response
 */

test('a request body can be read after the response, by another coroutine, and the next request waits for it', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        // Larger than 64 KiB: a smaller rest is read into the body for the application. Its first
        // byte is read in handle(): a large body nobody started reading makes the response close
        $body = str_repeat('0123456789', 10000);
        $conn = native_connect($addr);
        fwrite($conn, "POST /late-read?ms=50&first=1 HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\n0");
        $first = native_read_response($conn);
        expect([$first['status'], $first['headers']['connection'] ?? null])->toBe([202, null]);

        fwrite($conn, substr($body, 1) . "GET /late-result HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'] ?? null)->toBe(md5($body) . ':100000');
    } finally {
        native_stop($master);
    }
});

test('a pipelined request waits until the previous request body was read after its response', function () {
    $body    = str_repeat('0123456789', 10000);
    $handler = new class implements RequestHandlerInterface {
        public array $order = [];
        public string $got  = '';

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ('/next' === $request->getUri()->getPath()) {
                $this->order[] = 'GET /next';

                return new Response(200, [], 'next');
            }
            // Larger than 64 KiB, and read from before the response: the application's to read
            $first = $request->getBody()->read(1);
            phasync::go(function () use ($request, $first) {
                phasync::sleep(0.1);
                $this->got     = $first . $request->getBody()->getContents();
                $this->order[] = 'read-done';
            });

            return new Response(200, [], 'ok');
        }
    };
    $received = serve_in_process($handler, function ($conn) use ($body) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\n{$body}GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['ok', 'next']);
    expect($handler->got === $body)->toBeTrue();
    expect($handler->order)->toBe(['read-done', 'GET /next']);
});

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

test('a request body can be read after a Connection: close response: the client sees the response end at once', function () {
    $body    = str_repeat('x', 1000000);
    $handler = new class implements RequestHandlerInterface {
        public string $got = '';

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            phasync::go(function () use ($request) {
                phasync::sleep(0.1);
                $this->got = $request->getBody()->getContents();
            });

            return new Response(200, [], 'ok');
        }
    };
    $received = serve_in_process($handler, function ($conn) use ($body) {
        $writer = phasync::go(function () use ($conn, $body) {
            client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nConnection: close\r\nContent-Length: 1000000\r\n\r\n$body");
            stream_socket_shutdown($conn, STREAM_SHUT_WR);
        });
        $received = client_read_all($conn); // throws on a reset
        phasync::await($writer);

        return $received;
    }, tcp: true);
    $responses = parse_responses($received);

    expect(count($responses))->toBe(1);
    expect([$responses[0]['body'], $responses[0]['headers']['connection'] ?? null])->toBe(['ok', 'close']);
    expect(strlen($handler->got))->toBe(1000000);
});

test('a request body released unread is skipped, and a pipelined request is answered on the same connection', function () {
    $received = serve_in_process(streams_handler(fn () => new Response(200, [], 'Hello')), function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 10\r\n\r\n0123456789GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });
    $responses = parse_responses($received);

    expect(array_column($responses, 'body'))->toBe(['Hello', 'Hello']);
    expect(array_map(fn ($r) => $r['headers']['connection'] ?? null, $responses))->toBe([null, null]);
});

test('a small request body held unread after the response does not hold up the next request, and can still be read', function () {
    // As Slim's error handler keeps the last request it answered: its body is never released
    $handler  = new class implements RequestHandlerInterface {
        public ?ServerRequestInterface $kept = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ('/next' === $request->getUri()->getPath()) {
                return new Response(200, [], 'next');
            }
            $this->kept = $request;

            return new Response(404, [], 'nope');
        }
    };
    [$received, $seconds, $late] = serve_in_process($handler, function ($conn) use ($handler) {
        $start = microtime(true);
        client_write($conn, "POST /nope HTTP/1.1\r\nHost: t\r\nContent-Length: 10\r\n\r\n0123");
        phasync::sleep(0.1); // the rest arrives after the response
        client_write($conn, "456789GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        $received = '';
        while (2 !== count(parse_responses($received)) && microtime(true) - $start < 5) {
            $received .= fread(phasync::readable($conn, 5), 65536);
        }
        $seconds = microtime(true) - $start;
        $body    = $handler->kept->getBody();

        return [$received, $seconds, [$body->tell(), $body->read(4), $body->getContents(), $body->eof()]];
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['nope', 'next']);
    expect($seconds)->toBeLessThan(1.0);
    expect($late)->toBe([0, '0123', '456789', true]);
});

test('a small request body held unread keeps a keep-alive connection going in Slim, whose error handler holds it', function () {
    [$master, $addr] = native_start('slim.php', workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "POST /nope HTTP/1.1\r\nHost: t\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 5\r\n\r\na=b&c");
        expect(native_read_response($conn)['status'])->toBe(404);
        $start = microtime(true);
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");

        expect(native_read_response($conn)['body'] ?? null)->toBe('Hello');
        expect(microtime(true) - $start)->toBeLessThan(1.0);
    } finally {
        native_stop($master);
    }
});

test('a request body released after a partial read has its rest skipped', function () {
    $handler  = streams_handler(function (ServerRequestInterface $request) {
        phasync::go(static function () use ($request) {
            phasync::sleep(0.05);
            $request->getBody()->read(10);
        });

        return new Response(200, [], 'ok');
    });
    $received = serve_in_process($handler, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 30000\r\n\r\n" . str_repeat('x', 30000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['ok', 'ok']);
});

test('a released request body too large to skip closes the connection after the response', function () {
    $received = serve_in_process(streams_handler(fn () => new Response(200, [], 'ok')), function ($conn) {
        $writer   = phasync::go(fn () => client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 200000\r\n\r\n" . str_repeat('x', 200000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n") && stream_socket_shutdown($conn, STREAM_SHUT_WR));
        $received = client_read_all($conn);
        phasync::await($writer);

        return $received;
    });
    $responses = parse_responses($received);

    expect(count($responses))->toBe(1);
    expect($responses[0]['headers']['connection'] ?? null)->toBe('close');
});

test('a client leaving while a request body is read after the response fails that read, and the connection ends quietly', function () {
    $logger  = streams_logger();
    $handler = new class implements RequestHandlerInterface {
        public ?string $caught = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            phasync::go(function () use ($request) {
                phasync::sleep(0.05);
                try {
                    $request->getBody()->getContents();
                } catch (Throwable $e) {
                    $this->caught = $e::class;
                }
            });

            return new Response(200, [], 'ok');
        }
    };
    $received = serve_in_process($handler, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\nabc");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    }, $logger);

    expect(array_column(parse_responses($received), 'body'))->toBe(['ok']);
    expect($handler->caught)->toBe(RuntimeException::class);
    expect(preg_grep('/failed/', $logger->lines))->toBe([]);
});

test('reading a request body after its connection closed throws a RuntimeException', function () {
    $handler = new class implements RequestHandlerInterface {
        public ?string $caught = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            phasync::go(function () use ($request) {
                phasync::sleep(0.05);
                try {
                    $request->getBody()->read(10);
                } catch (Throwable $e) {
                    $this->caught = $e::class;
                }
            });

            throw new LogicException('the application failed'); // 500, and the connection closes
        }
    };
    $received = serve_in_process($handler, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nContent-Length: 100\r\n\r\n");

        return client_read_all($conn);
    });

    expect(parse_responses($received)[0]['status'])->toBe(500);
    expect($handler->caught)->toBe(RuntimeException::class);
});

test('a malformed chunked body found after the response fails the read and closes the connection', function () {
    $handler  = new class implements RequestHandlerInterface {
        public ?string $caught = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            phasync::go(function () use ($request) {
                phasync::sleep(0.02);
                try {
                    $request->getBody()->getContents();
                } catch (Throwable $e) {
                    $this->caught = $e::class;
                }
            });

            return new Response(200, [], 'ok');
        }
    };
    $received = serve_in_process($handler, function ($conn) {
        client_write($conn, "POST / HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\nzz\r\nhello\r\n0\r\n\r\nGET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);

        return client_read_all($conn);
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['ok']);
    expect($handler->caught)->toBe(Swerve\Http\HttpError::class);
});

test('a large request body held without ever being read after the response closes the connection after 30 s, logged', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conn                   = native_connect($addr);
    fwrite($conn, "POST /hold HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\n");
    $response = native_read_response($conn);
    expect([$response['body'], $response['headers']['connection'] ?? null])->toBe(['holding', 'close']);
    $start = microtime(true);

    log_wait($log, '/held unread/', 35);
    expect(microtime(true) - $start)->toBeGreaterThan(29.0)->toBeLessThan(33.0);
    native_stop($process);
})->group('slow');

test('a request body read slowly after the response is never taken for one held unread', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conn                   = native_connect($addr);
    stream_set_timeout($conn, 60);
    // Larger than 64 KiB, so the application reads it from the socket; 31 s between its reads
    fwrite($conn, "POST /slow-consume?ms=31000 HTTP/1.1\r\nHost: t\r\nContent-Length: 70000\r\n\r\n" . str_repeat('x', 70000));
    expect(native_read_response($conn)['status'])->toBe(202);
    sleep(35);

    stream_set_blocking($conn, false);
    expect([fread($conn, 1), feof($conn)])->toBe(['', false]); // still open
    expect(log_count($log, '/held unread/'))->toBe(0);
    native_stop($process);
})->group('slow');

test('a small request body read after the response, from another coroutine, gives a slow client the time it would have if handle() read it', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        // Its reader starts 100 ms after the response; the client sends 1000 bytes every 0.9 s,
        // over 7 s in all, within the allowance of a body being read (10 s, one more per KiB)
        $conn = native_connect($addr);
        fwrite($conn, "POST /late-read?ms=100 HTTP/1.1\r\nHost: t\r\nContent-Length: 8000\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(202);
        $body = '';
        for ($i = 0; $i < 8; ++$i) {
            usleep(900_000);
            $body .= $piece = str_repeat(chr(65 + $i), 1000);
            @fwrite($conn, $piece);
        }
        @fwrite($conn, "GET /late-result HTTP/1.1\r\nHost: t\r\n\r\n");

        expect(native_read_response($conn)['body'] ?? null)->toBe(md5($body) . ':8000');
    } finally {
        native_stop($master);
    }
});

test('a small request body read partly, then held (Slim\'s error handler keeps the request), does not hold up the next request, and its rest can still be read', function () {
    $handler = new class implements RequestHandlerInterface {
        public ?ServerRequestInterface $kept = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ('/next' === $request->getUri()->getPath()) {
                return new Response(200, [], 'next');
            }
            $this->kept = $request;
            $request->getBody()->read(4);

            return new Response(400, [], 'bad magic');
        }
    };
    [$received, $seconds, $late] = serve_in_process($handler, function ($conn) use ($handler) {
        $start = microtime(true);
        client_write($conn, "POST /upload HTTP/1.1\r\nHost: t\r\nContent-Length: 1000\r\n\r\n" . str_repeat('x', 1000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        $received = '';
        while (2 !== count(parse_responses($received)) && microtime(true) - $start < 3) {
            try {
                $received .= fread(phasync::readable($conn, 0.5), 65536);
            } catch (phasync\TimeoutException) {
            }
        }
        $seconds = microtime(true) - $start;
        $body    = $handler->kept->getBody();

        return [$received, $seconds, [$body->tell(), strlen($body->getContents()), $body->eof()]];
    });

    expect(array_column(parse_responses($received), 'body'))->toBe(['bad magic', 'next']);
    expect($seconds)->toBeLessThan(1.0);
    expect($late)->toBe([4, 996, true]);
});

test('a large request body read partly inside handle(), then held and never read again, closes the connection after 30 s, logged', function () {
    // Slim's error handler keeps the request whose handler read the start of the body and threw
    $handler = new class implements RequestHandlerInterface {
        public ?ServerRequestInterface $kept = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            if ('/next' === $request->getUri()->getPath()) {
                return new Response(200, [], 'next');
            }
            $this->kept = $request;
            $request->getBody()->read(4);

            return new Response(400, [], 'bad magic');
        }
    };
    $logger                        = streams_logger();
    [$received, $seconds, $closed] = serve_in_process($handler, function ($conn) {
        $start = microtime(true);
        client_write($conn, "POST /upload HTTP/1.1\r\nHost: t\r\nContent-Length: 100000\r\n\r\n" . str_repeat('x', 100000) . "GET /next HTTP/1.1\r\nHost: t\r\n\r\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);
        $received = '';
        try {
            while (!feof($conn) && microtime(true) - $start < 40) {
                $received .= fread(phasync::readable($conn, 40 - (microtime(true) - $start)), 65536);
            }
        } catch (phasync\TimeoutException) {
        }

        return [$received, microtime(true) - $start, feof($conn)];
    }, $logger, tcp: true);

    expect(array_column(parse_responses($received), 'body'))->toBe(['bad magic']);
    expect($closed)->toBeTrue();
    expect($seconds)->toBeGreaterThan(29.0)->toBeLessThan(33.0);
    expect(implode("\n", $logger->lines))->toContain('held unread');
})->group('slow');

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

        // A read from another coroutine waits for the status, then finds the (empty) body's end
        fwrite($conn, "GET /late-probe HTTP/1.1\r\nHost: t\r\n$upgrade\r\n");
        expect(native_read_response($conn)['body'])->toBe('probing');
        fwrite($conn, "GET /late-result HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('["",true]');

        // What follows the upgrade request is the next request
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n$upgrade\r\nGET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
        expect((int) native_read_response($conn)['body'])->toBeGreaterThan(0);
    } finally {
        native_stop($master);
    }
});

test('reading an upgrade request\'s body inside handle() declines the upgrade instead of waiting', function () {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn  = native_connect($addr);
        $start = microtime(true);
        fwrite($conn, "GET /read-in-handle HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['body'], $response['headers']['connection'] ?? null])->toBe([200, '[]', 'Upgrade']); // kept alive
        expect(microtime(true) - $start)->toBeLessThan(1.0);

        fwrite($conn, "GET /read-in-handle?then=101 HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n");
        expect(native_read_response($conn)['status'])->toBe(500);
    } finally {
        native_stop($master);
    }
});

test('a read waiting for an upgrade\'s response status, which handle() waits for, ends when the client closes', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $pid                    = (int) probe($addr, '/pid');
    $fds                    = static fn () => count(scandir("/proc/$pid/fd")) - 2;
    $base                   = $fds();
    // As curl --http2 sends every request to an http:// URL
    $upgrade = "Connection: Upgrade, HTTP2-Settings\r\nUpgrade: h2c\r\nHTTP2-Settings: AAMAAABkAAQCAAAAAAIAAAAA\r\n";
    $conn    = native_connect($addr);
    fwrite($conn, "POST /child-read HTTP/1.1\r\nHost: t\r\n{$upgrade}Content-Length: 5\r\n\r\nhello");
    stream_socket_shutdown($conn, STREAM_SHUT_WR); // it sends nothing more
    $start = microtime(true);

    expect(native_read_response($conn)['body'] ?? null)->toBe('got:hello');
    expect(microtime(true) - $start)->toBeLessThan(2.0);
    fclose($conn);

    // One that leaves without waiting for the response frees its connection too
    $conn = native_connect($addr);
    fwrite($conn, "POST /child-read HTTP/1.1\r\nHost: t\r\n{$upgrade}Content-Length: 5\r\n\r\nhello");
    usleep(200_000);
    fclose($conn);
    $deadline = microtime(true) + 3;
    while ($fds() > $base && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($fds())->toBe($base);
    native_stop($process);
});

test('a read waiting for an upgrade\'s response status, which handle() waits for, gives the upgrade up after the body allowance, also for a client that stays or left with bytes past the framing', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $pid                    = (int) probe($addr, '/pid');
    $fds                    = static fn () => count(scandir("/proc/$pid/fd")) - 2;
    $base                   = $fds();
    $upgrade                = "Connection: Upgrade, HTTP2-Settings\r\nUpgrade: h2c\r\nHTTP2-Settings: AAMAAABkAAQCAAAAAAIAAAAA\r\n";
    // Clients that leave with a byte sent past the framing: it might be the tunnel's first
    for ($i = 0; $i < 3; ++$i) {
        $gone = native_connect($addr);
        fwrite($gone, "POST /child-read HTTP/1.1\r\nHost: t\r\n{$upgrade}Content-Length: 5\r\n\r\nhelloX");
        usleep(100_000);
        fclose($gone);
    }
    // A client that stays, as curl --http2 does, waiting for the response
    $conn = native_connect($addr);
    stream_set_timeout($conn, 20);
    fwrite($conn, "POST /child-read HTTP/1.1\r\nHost: t\r\n{$upgrade}Content-Length: 5\r\n\r\nhello");
    $start = microtime(true);

    $response = native_read_response($conn);
    expect([$response['body'] ?? null, $response['headers']['connection'] ?? null])->toBe(['got:hello', null]);
    expect(microtime(true) - $start)->toBeGreaterThan(9.0)->toBeLessThan(12.0); // BODY_TIMEOUT
    fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
    expect(native_read_response($conn)['body'] ?? null)->toBe('Hello');
    fclose($conn);
    $deadline = microtime(true) + 2;
    while ($fds() > $base && microtime(true) < $deadline) {
        usleep(50_000);
    }
    expect($fds())->toBe($base);
    native_stop($process);
})->group('slow');

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

test('a 101 goes out at once, then both directions are the raw connection, beyond the HTTP limits', function (array $args) {
    [$master, $addr] = native_start(args: ['--max-body=1000', ...$args], workers: 1);
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
        expect(fcgi_read_exactly($conn, 5))->toBe('hello');

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
        expect(fcgi_read_exactly($conn, 5))->toBe('early');
    } finally {
        native_stop($master);
    }
})->with([
    'streamed'           => [[]],
    '--buffer-responses' => [['--buffer-responses']],
]);

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

        // Also the upgrade request's own (framed) body, sent after the 101
        $conn = native_connect($addr);
        stream_set_timeout($conn, 20);
        fwrite($conn, "GET /upgrade-upper HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: upper\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n");
        expect(native_read_head($conn)['status'])->toBe(100);
        expect(native_read_head($conn)['status'])->toBe(101);
        sleep(11);

        fwrite($conn, 'body5hello');
        expect(fcgi_read_exactly($conn, 10))->toBe('BODY5HELLO');
    } finally {
        native_stop($master);
    }
})->group('slow');

test('a tunnel whose response ended while its input is held unread, or read on, closes without a reset: the client gets all of it', function (string $query) {
    [$master, $addr] = native_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /upgrade-hold?kb=1024$query HTTP/1.1\r\nHost: t\r\nConnection: Upgrade\r\nUpgrade: x\r\n\r\n");
        expect(native_read_head($conn)['status'])->toBe(101);
        fwrite($conn, 'client-bytes');
        // Past the 2 s the application gets to read the input once the response ended, while
        // the connection closes
        usleep(2_700_000);
        fwrite($conn, 'more-bytes');
        usleep(2_300_000);
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

        // The server knows no WebSocket: only the application-side classes speak it
        exec('grep -rilE ' . escapeshellarg('sec-websocket|258EAFA5|websocket') . ' ' . escapeshellarg(__DIR__ . '/../src'), $files);
        sort($files);
        expect(array_map(basename(...), $files))->toBe(['ProtocolUpgrade.php', 'WebSocket.php']);
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
            expect(fcgi_read_exactly($conn, 6))->toBe(sprintf("%03d:%d\n", $i, $round));
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
});

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
