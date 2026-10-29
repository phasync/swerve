<?php

// Swerve 0.2: the master accepts every connection, hands a readable one to a worker, and takes
// it back after each kept-alive response; workers loop worker.php through run_next(), each
// running the app's swerve.php, whose Swerve::accept() calls serve the requests.

function accept_start(string $fixture, int $workers = 2): array
{
    $ext = getenv('PHASYNC_EXT_SO');
    if (!$ext) {
        throw new RuntimeException('These tests need ext-phasync with run_next(): set PHASYNC_EXT_SO');
    }
    $addr    = free_address();
    $log     = temp_path();
    $process = proc_open(
        ['setsid', PHP_BINARY, '-d', "extension=$ext", __DIR__ . '/../bin/swerve.php', "--http=$addr", "--workers=$workers", "--log=$log", __DIR__ . "/Fixtures/accept/$fixture"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        __DIR__ . '/Fixtures/accept',
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    $deadline                   = microtime(true) + 10;
    while (null === probe($addr, '/hello')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving on $addr:\n" . file_get_contents($log));
        }
        usleep(20000);
    }

    return [$process, $addr, $log];
}

/** A request on an open connection: the response, or null when the connection ended first. */
function accept_request($conn, string $path, string $extra = ''): ?array
{
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\n$extra\r\n");

    return native_read_response($conn);
}

it('serves a closure from a while (accept()) loop in every worker', function () {
    [, $addr] = accept_start('hello.php', 2);
    $pids     = [];
    for ($i = 0; $i < 40; ++$i) {
        $body = probe($addr, '/');
        expect($body)->toMatch('/^Hello from \d+\n$/');
        $pids[$body] = true;
    }
    expect(count($pids))->toBe(2);
});

it('keeps a connection alive across requests, each handed to whichever worker is free', function () {
    [, $addr] = accept_start('hello.php', 2);
    $conn     = native_connect($addr);
    $start    = microtime(true);
    for ($i = 0; $i < 20; ++$i) {
        $response = accept_request($conn, '/');
        expect($response['status'])->toBe(200)
            ->and($response['body'])->toMatch('/^Hello from \d+\n$/');
    }
    // Each takes about a millisecond; Nagle without TCP_NODELAY made each 40 ms
    expect(microtime(true) - $start)->toBeLessThan(0.4);
    fclose($conn);
});

it('runs a script handler as a fresh request each time, with its globals', function () {
    [, $addr] = accept_start('classic.php', 2);
    for ($i = 0; $i < 30; ++$i) {
        expect(probe($addr, "/page$i"))->toMatch("/^site-\\d+ count=1 uri=\\/page$i\\n$/");
    }
});

it('answers every request of many parallel keep-alive clients exactly once, never empty', function () {
    [, $addr] = accept_start('classic.php', 3);
    $clients  = [];
    for ($c = 0; $c < 16; ++$c) {
        $clients[$c] = native_connect($addr);
    }
    for ($round = 0; $round < 10; ++$round) {
        foreach ($clients as $c => $conn) {
            fwrite($conn, "GET /c$c-r$round HTTP/1.1\r\nHost: test\r\n\r\n");
        }
        foreach ($clients as $c => $conn) {
            $response = native_read_response($conn);
            expect($response['status'])->toBe(200)
                ->and($response['body'])->toMatch("/ count=1 uri=\\/c$c-r$round\\n$/");
        }
    }
});

it('closes the connection when the application says Connection: close', function () {
    [, $addr] = accept_start('classic.php', 1);
    $conn     = native_connect($addr);
    $response = accept_request($conn, '/close');
    expect($response['headers']['connection'] ?? '')->toBe('close')
        ->and(native_closed($conn))->toBeTrue();
});

it('runs a PSR-7 closure: ServerRequestInterface in, ResponseInterface out', function () {
    [, $addr] = accept_start('psr7.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "POST /psr HTTP/1.1\r\nHost: test\r\nContent-Length: 4\r\n\r\nbody");
    $response = native_read_response($conn);
    expect($response['status'])->toBe(201)
        ->and($response['headers']['x-method'])->toBe('POST')
        ->and($response['body'])->toBe('psr7 /psr body');
});

it('upgrades on a PSR-7 101 response: the request body is what the client sends after it, the response body goes back', function () {
    [, $addr] = accept_start('psr7.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "GET /shout HTTP/1.1\r\nHost: test\r\nConnection: Upgrade\r\nUpgrade: shout\r\n\r\nhello");
    $head = native_read_head($conn);
    expect([$head['status'], $head['headers']['upgrade'] ?? null])->toBe([101, 'shout'])
        ->and(read_exactly($conn, 5))->toBe('HELLO');
    fwrite($conn, ' world');
    expect(read_exactly($conn, 6))->toBe(' WORLD');
    stream_socket_shutdown($conn, STREAM_SHUT_WR); // the request body ends: so does the response body, and the connection
    expect(native_closed($conn))->toBeTrue();
});

it('refuses a PSR-7 101 response in a swerve.php that accepts once: the connection could not outlive its run', function () {
    [, $addr] = accept_start('psr7once.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "GET / HTTP/1.1\r\nHost: test\r\nConnection: Upgrade\r\nUpgrade: shout\r\n\r\n");
    expect(native_read_head($conn)['status'])->toBe(500);
});

it('runs a PSR-7 closure under sapi(): PHP_SAPI, header(), and a form as getParsedBody() and $_POST', function () {
    [, $addr] = accept_start('psr7sapi.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "POST / HTTP/1.1\r\nHost: test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 7\r\n\r\na=1&b=2");
    $response = native_read_response($conn);
    expect($response['headers']['x-from-header'])->toBe('yes')
        ->and($response['body'])->toBe('swerve {"a":"1","b":"2"} {"a":"1","b":"2"}');
});

it('replaces a worker that was killed', function () {
    [, $addr] = accept_start('hello.php', 1);
    $pid      = (int) substr(probe($addr, '/'), strlen('Hello from '));
    posix_kill($pid, SIGKILL);
    $deadline = microtime(true) + 5;
    while (null === ($body = probe($addr, '/')) && microtime(true) < $deadline) {
        usleep(20000);
    }
    expect($body)->toMatch('/^Hello from \d+\n$/')
        ->and((int) substr($body, strlen('Hello from ')))->not->toBe($pid);
});

it('gives a closure handler the request body through php://input and $_POST', function () {
    [, $addr] = accept_start('body.php', 1);
    $conn     = native_connect($addr);
    $big      = str_repeat('x', 200000);
    fwrite($conn, "POST / HTTP/1.1\r\nHost: test\r\nContent-Length: " . strlen($big) . "\r\n\r\n$big");
    expect(native_read_response($conn)['body'])->toBe("200000 []\n");
    fwrite($conn, "POST / HTTP/1.1\r\nHost: test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 7\r\n\r\na=1&b=2");
    expect(native_read_response($conn)['body'])->toBe("7 {\"a\":\"1\",\"b\":\"2\"}\n");
    // $_REQUEST in request_order (GP): the form's value wins over the query's
    fwrite($conn, "POST /?a=get&c=3 HTTP/1.1\r\nHost: test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 6\r\n\r\na=post");
    expect(native_read_response($conn)['body'])->toBe("{\"a\":\"post\",\"c\":\"3\"}\n");
});

it('serves static files without using up accept(), as long as the client has the next request written', function () {
    [, $addr] = accept_start('static.php', 1);
    $conn     = native_connect($addr);
    $first    = accept_request($conn, '/');
    expect($first['body'])->toMatch('/^php run \d+$/');
    $run      = (int) substr($first['body'], 8);
    // Three files and a PHP request in one write: all of them in the next run of static.php
    fwrite($conn, str_repeat("GET /a.txt HTTP/1.1\r\nHost: test\r\n\r\n", 3) . "GET / HTTP/1.1\r\nHost: test\r\n\r\n");
    for ($i = 0; $i < 3; ++$i) {
        $file = native_read_response($conn);
        expect($file['status'])->toBe(200)
            ->and($file['headers']['content-type'])->toBe('text/plain; charset=utf-8')
            ->and($file['body'])->toBe("hello file\n");
    }
    expect(native_read_response($conn)['body'])->toBe('php run ' . ($run + 1));
});

it('goes on with another connection when one goes idle after static files only', function () {
    [, $addr] = accept_start('static.php', 1);
    $conn     = native_connect($addr);
    $run      = (int) substr(accept_request($conn, '/')['body'], 8);
    expect(accept_request($conn, '/a.txt')['body'])->toBe("hello file\n");
    $other = native_connect($addr);
    expect(accept_request($other, '/a.txt')['body'])->toBe("hello file\n")
        // the files used up no run of static.php: the next PHP request is in the next run
        ->and(accept_request($conn, '/')['body'])->toBe('php run ' . ($run + 1));
});

it('finishes the response of a request that ends with die(), and keeps the connection', function () {
    [, $addr] = accept_start('die.php', 1);
    $conn     = native_connect($addr);
    $first    = accept_request($conn, '/');
    expect($first['complete'])->toBeTrue()
        ->and($first['body'])->toMatch("/^Hello, World run \\d+\n$/");
    // The same connection, in the next run of die.php
    $second = accept_request($conn, '/');
    expect($second['complete'])->toBeTrue()
        ->and($second['body'])->toMatch("/^Hello, World run \\d+\n$/")
        ->and($second['body'])->not->toBe($first['body']);
});

it('sends the status and headers of a request that dies before any output', function () {
    [, $addr] = accept_start('die.php', 1);
    $response = accept_request(native_connect($addr), '/early');
    expect($response['status'])->toBe(404)
        ->and($response['headers']['x-app'])->toBe('early')
        ->and($response['complete'])->toBeTrue();
});

it('gives the connection to Swerve::upgrade()\'s closure once the request ended', function () {
    [, $addr] = accept_start('upgrade.php', 1);
    $conn     = native_connect($addr);
    $raw      = accept_request($conn, '/raw');
    expect($raw['headers']['x-raw'])->toBe('yes')
        ->and($raw['headers'])->not->toHaveKey('x-ignored')
        ->and($raw['body'])->toBe('written to the ClientRequest: echo refused, aborted')
        ->and($raw['complete'])->toBeTrue();
    // The connection goes on with the next request
    expect(accept_request($conn, '/late')['body'])->toBe('begun, upgrade refused');
    $sse = accept_request($conn, '/sse');
    expect($sse['headers']['content-type'])->toStartWith('text/event-stream')
        ->and($sse['body'])->toBe("data: one\n\ndata: two\n\n");
});

it('serves a WebSocket on an upgraded request', function () {
    [, $addr] = accept_start('upgrade.php', 1);
    $ws       = ws_connect($addr, '/ws');
    ws_send($ws, 0x1, 'hi');
    expect(ws_read($ws))->toBe([0x1, 'echo hi']);
});

it('agrees permessage-deflate for an upgrade closure, in place and moved to a WebSocket worker', function (string $script) {
    [, $addr] = accept_start($script, 1);
    $conn     = native_connect($addr);
    fwrite($conn, "GET /ws HTTP/1.1\r\nHost: t\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n\r\n");
    $head = native_read_head($conn);
    expect([$head['status'], $head['headers']['sec-websocket-extensions'] ?? null])->toBe([101, 'permessage-deflate; client_no_context_takeover']);
    $text = str_repeat('deflated ', 50);
    $mask = "\1\2\3\4";
    $data = substr(deflate_add(deflate_init(ZLIB_ENCODING_RAW), $text, ZLIB_SYNC_FLUSH), 0, -4);
    fwrite($conn, "\xC1" . chr(0x80 | strlen($data)) . $mask . ($data ^ substr(str_repeat($mask, intdiv(strlen($data), 4) + 1), 0, strlen($data))));
    $b       = fread($conn, 2);
    $length  = ord($b[1]) & 0x7F;
    $length  = 126 === $length ? unpack('n', fread($conn, 2))[1] : $length;
    $payload = stream_get_contents($conn, $length);
    expect(ord($b[0]) & 0x40)->toBe(0x40);
    expect(inflate_add(inflate_init(ZLIB_ENCODING_RAW), $payload . "\x00\x00\xff\xff", ZLIB_SYNC_FLUSH))->toContain(" $text"); // upgrade.php: "echo …", short-lived.php: "hello … from <pid>"
})->with(['upgrade.php', 'short-lived.php']);

it('runs an upgrade closure with the request\'s superglobals, session data and timezone, in place and moved to a WebSocket worker', function (string $script) {
    [, $addr] = accept_start($script, 1);
    $ws       = ws_connect($addr, '/ws-state?n=1');
    ws_send($ws, 0x1, 'hi');
    // The request's own $_GET and timezone as they were at upgrade(), not what came after
    expect(ws_read($ws))->toBe([0x1, '[{"n":"1"},"n=1",{"user":7},"Asia\\/Tokyo"]']);
    if ('upgrade.php' === $script) {
        // nor did they reach another request on the same worker
        expect(accept_request(native_connect($addr), '/tz')['body'])->toBe('[[],"' . addcslashes(date_default_timezone_get(), '/') . '"]');
    }
})->with(['upgrade.php', 'short-lived.php']);

it('gives a request the worker\'s own $_SERVER entries along with its own', function () {
    putenv('SWERVE_TEST_ENV=1');
    try {
        [, $addr] = accept_start('server-env.php', 1);
    } finally {
        putenv('SWERVE_TEST_ENV');
    }
    expect(accept_request(native_connect($addr), '/a')['body'])->toBe('env /a')
        ->and(accept_request(native_connect($addr), '/b')['body'])->toBe('env /b');
});

it('sends header names as the script wrote them, a name\'s later lines joining its first', function () {
    [, $addr] = accept_start('header-case.php', 1);
    $conn     = native_connect($addr);
    fwrite($conn, "GET / HTTP/1.1\r\nHost: t\r\n\r\n");
    stream_set_blocking($conn, true);
    for ($head = ''; !str_contains($head, "\r\n\r\n") && !feof($conn);) {
        $head .= fread($conn, 8192);
    }
    expect($head)->toContain("\r\nX-Custom-Name: a\r\nX-Custom-Name: b\r\n")
        ->and($head)->toContain("\r\nLocation: /elsewhere\r\n");
});

it('forks the workers from the master, so they share APCu', function () {
    [, $addr] = accept_start('apcu.php', 2);
    [$stored] = explode(' ', accept_request(native_connect($addr), '/set')['body']);
    // A new connection each time, until another worker answers
    for ($i = 0; $i < 50; ++$i) {
        [$seen, $pid] = explode(' ', accept_request(native_connect($addr), '/')['body']);
        if ($pid !== $stored) {
            break;
        }
    }
    expect($pid)->not->toBe($stored)
        ->and($seen)->toBe($stored);
})->skip(!extension_loaded('apcu'), 'needs APCu');

it('gives what a handler threw to the swerve.php that awaits the request; the client gets a 500', function () {
    [, $addr] = accept_start('throws.php', 1);
    $response = accept_request(native_connect($addr), '/boom');
    expect($response['status'])->toBe(500);
    // swerve.php gets the exception after the response went out
    for ($deadline = microtime(true) + 2; [] === ($caught = glob(sys_get_temp_dir() . '/swerve-throws-*')) && microtime(true) < $deadline;) {
        usleep(10000);
    }
    expect($caught)->toHaveCount(1)
        ->and(file_get_contents($caught[0]))->toBe('boom');
    unlink($caught[0]);
});

it('serves the next request while an upgraded connection goes on, also when swerve.php awaits each request', function () {
    [, $addr] = accept_start('upgrade-await.php', 1);
    $ws       = ws_connect($addr, '/ws');
    expect(accept_request(native_connect($addr), '/')['body'])->toBe('hello');
    ws_send($ws, 0x1, 'still here');
    expect(ws_read($ws))->toBe([0x1, 'echo still here']);
});

it('moves a WebSocket out of a lifecycle that serves one request, with what its closure captured', function () {
    [, $addr] = accept_start('short-lived.php', 1);
    $ws       = ws_connect($addr, '/ws');
    ws_send($ws, 0x1, 'hi');
    [$type, $reply] = ws_read($ws);
    $worker         = accept_request(native_connect($addr), '/')['body'];
    expect($type)->toBe(0x1)
        ->and($reply)->toStartWith('echo hi from ')
        ->and(substr($reply, 13))->not->toBe($worker);
    // The worker's lifecycles go on; the WebSocket stays open
    accept_request(native_connect($addr), '/');
    ws_send($ws, 0x1, 'again');
    expect(ws_read($ws)[1])->toStartWith('echo again from ');
});

it('stops a request whose client is gone at its next output, and only that request', function () {
    [, $addr] = accept_start('abort.php', 1);
    $id       = uniqid();
    $marker   = sys_get_temp_dir() . "/swerve-abort-$id";
    $worker   = accept_request(native_connect($addr), '/')['body'];
    $stream   = native_connect($addr);
    fwrite($stream, "GET /stream?id=$id HTTP/1.1\r\nHost: test\r\n\r\n");
    fread($stream, 8192);
    fclose($stream);
    usleep(300000);
    $stopped = (int) file_get_contents($marker);
    usleep(200000);
    expect((int) file_get_contents($marker))->toBe($stopped)
        ->and($stopped)->toBeLessThan(1000)
        ->and(file_exists("$marker-finally"))->toBeFalse()
        ->and(accept_request(native_connect($addr), '/')['body'])->toBe($worker);
    @unlink($marker);
});

it('refuses Swerve::upgrade() at the call when the request can\'t be upgraded so', function () {
    [, $addr] = accept_start('upgrade.php', 1);
    $uncaught = accept_request(native_connect($addr), '/ws-uncaught');
    expect($uncaught['status'])->toBe(426)
        ->and($uncaught['body'])->not->toContain('not reached')
        ->and(accept_request(native_connect($addr), '/ws-caught')['body'])->toBe('refused 426')
        ->and(accept_request(native_connect($addr), '/buffered')['body'])->toBe('refused, output buffered')
        ->and(accept_request(native_connect($addr), '/')['body'])->toBe('hello');
});

it('refuses at the call a WebSocket closure that must move and captures what can\'t be serialized', function () {
    [, $addr] = accept_start('short-lived.php', 1);
    $handshake = "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n";
    expect(accept_request(native_connect($addr), '/ws-resource', $handshake)['body'])->toStartWith('refused: Swerve::upgrade(): the closure moves');
});

it('runs the PHP file a request names below the static directory, index.php for a directory, and the script for the rest', function () {
    [, $addr] = accept_start('routed.php', 1);
    $get = static fn (string $path) => accept_request(native_connect($addr), $path)['body'];
    expect($get('/wp-login.php?x=1'))->toBe('wp-login.php /wp-login.php /wp-login.php - routed routed')
        ->and($get('/wp-admin/'))->toBe('index.php /wp-admin/index.php /wp-admin/index.php - routed wp-admin')
        ->and($get('/wp-admin/admin-ajax.php/extra/path'))->toBe('admin-ajax.php /wp-admin/admin-ajax.php /wp-admin/admin-ajax.php/extra/path /extra/path routed wp-admin')
        ->and($get('/2026/10/a-post/'))->toBe('index.php /index.php /index.php - routed routed')
        ->and($get('/missing.php'))->toBe('index.php /index.php /index.php - routed routed')
        ->and($get('/.hidden/hook.php'))->toBe('index.php /index.php /index.php - routed routed')
        ->and($get('/wp-admin/../wp-login.php'))->toBe('index.php /index.php /index.php - routed routed')
        ->and($get('/style.css'))->toBe("style\n");
});

it('parses cookies as PHP does: names as sent, values rawurldecoded, the first of a name winning', function () {
    [, $addr] = accept_start('cookies.php', 1);
    // What php -S (PHP's own parser) makes of the same header
    expect(accept_request(native_connect($addr), '/', "Cookie: a.b=1; c[d]=2; e%20f=3; g=x%20y+z; a.b=dup; h=; i\r\n")['body'])
        ->toBe('{"a_b":"1","c":{"d":"2"},"e%20f":"3","g":"x y+z","h":"","i":""}');
});

it('starts a WebSocket worker when the first WebSocket moves, and another when those running are busy', function () {
    [$process, $addr] = accept_start('short-lived.php', 2);
    $children         = static fn () => count(array_filter(explode("\n", (string) shell_exec('pgrep -P ' . proc_get_status($process)['pid']))));
    expect($children())->toBe(2); // the two workers, no WebSocket worker yet

    $first = ws_connect($addr, '/ws-busy');
    ws_send($first, 0x1, 'hi');
    [, $reply] = ws_read($first);
    expect($children())->toBe(3);
    $busy = substr($reply, strlen('hi from '));

    // While that one is busy, a second WebSocket waits unread: the master starts another for it
    ws_send($first, 0x1, 'block');
    usleep(100000);
    $second = ws_connect($addr, '/ws-busy');
    ws_send($second, 0x1, 'hi');
    $started = microtime(true);
    [, $other] = ws_read($second);
    expect(substr($other, strlen('hi from ')))->not->toBe($busy)
        ->and(microtime(true) - $started)->toBeLessThan(1.5)
        ->and($children())->toBe(4)
        ->and(ws_read($first)[1])->toBe("block from $busy");
});

it('a moved WebSocket that needs code the application does not autoload is fatal: logged, and the WebSocket worker exits; the next starts another', function (string $path) {
    [, $addr, $log] = accept_start('short-lived.php', 1);
    $ws = ws_connect($addr, $path);
    ws_send($ws, 0x1, 'hi');
    do {
        $frame = ws_read($ws); // a 1011, if the callback got to run
    } while (null !== $frame);
    log_wait($log, '/critical .*has the application\'s Composer autoload/s', 5);
    // The next WebSocket that moves gets a new WebSocket worker
    $ws = ws_connect($addr, '/ws');
    ws_send($ws, 0x1, 'hi');
    expect(ws_read($ws)[1])->toStartWith('echo hi from ');
})->with([
    'a function of swerve.php'                  => '/ws-function',
    'a captured object of a class of swerve.php' => '/ws-class',
]);

it('ends a response with Connection: close although a coroutine of the request runs on (#98)', function () {
    [, $addr] = accept_start('background.php', 1);
    $file     = temp_path();
    $conn     = native_connect($addr);
    $start    = microtime(true);
    $response = accept_request($conn, '/background?file=' . urlencode($file), "Connection: close\r\n");
    expect($response['body'])->toBe('started')
        ->and(native_closed($conn))->toBeTrue()
        ->and(microtime(true) - $start)->toBeLessThan(0.25); // the coroutine sleeps 0.3 s
});

it('serves the next request on a kept-alive connection while a coroutine of the last one runs on, whose output goes nowhere and whose client is gone (#98)', function () {
    [, $addr] = accept_start('background.php', 1);
    $file     = temp_path();
    $conn     = native_connect($addr);
    $start    = microtime(true);
    expect(accept_request($conn, '/background?file=' . urlencode($file))['body'])->toBe('started')
        ->and(accept_request($conn, '/hello')['body'])->toBe("hello\n")
        ->and(microtime(true) - $start)->toBeLessThan(0.25); // the coroutine sleeps 0.3 s
    usleep(400_000);
    expect(accept_request($conn, '/hello', "Connection: close\r\n")['body'])->toBe("hello\n") // nothing stray
        ->and(native_closed($conn))->toBeTrue();
    $deadline = microtime(true) + 2;
    while ('' === file_get_contents($file)) {
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    expect(file_get_contents($file))->toBe('aborted'); // connection_aborted(): the client is gone for it
});

foreach (['fastcgi_finish_request()' => '/finish', 'a body of its Content-Length' => '/length'] as $what => $route) {
    it("completes a response at $what while the request runs on (#98)", function () use ($route) {
        [, $addr] = accept_start('background.php', 1);
        $file     = temp_path();
        $conn     = native_connect($addr);
        $start    = microtime(true);
        $response = accept_request($conn, "$route?file=" . urlencode($file));
        expect($response['body'])->toBe('early')
            ->and($response['headers']['x-early'] ?? null)->toBe('yes')
            ->and(accept_request($conn, '/hello')['body'])->toBe("hello\n")
            ->and(microtime(true) - $start)->toBeLessThan(0.25); // the request sleeps 0.3 s after
        $deadline = microtime(true) + 2;
        while ('' === file_get_contents($file)) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(20_000);
        }
        expect(file_get_contents($file))->toBe('aborted ' . ('/finish' === $route ? 'true' : 'NULL') . ' false')
            ->and(accept_request($conn, '/hello', "Connection: close\r\n")['body'])->toBe("hello\n"); // nothing stray
    });
}
