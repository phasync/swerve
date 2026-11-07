<?php

/*
 * The documented API is the same whether `swerve` starts the application or the application
 * starts a Swerve\Server: one application (tests/Fixtures/parity/routes.php), served both ways,
 * each test run against both.
 */

/**
 * Serve the parity application: 'swerve' runs bin/swerve.php with one worker, 'server' a process
 * of its own with a Swerve\Server.
 *
 * @return array{0: string, 1: Closure(): void} its address, and what stops it as each is stopped
 */
function parity_start(string $mode): array
{
    $ext = getenv('PHASYNC_EXT_SO') ?: throw new RuntimeException('Set PHASYNC_EXT_SO to a built ext-phasync');
    if ('swerve' === $mode) {
        $addr    = free_address();
        $process = swerve_spawn(["--http=$addr", '--workers=1'], __DIR__ . '/Fixtures/parity/swerve.php');
        $deadline = microtime(true) + 10;
        while (null === probe($addr, '/file.txt')) {
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                throw new RuntimeException("swerve did not start serving on $addr");
            }
            usleep(20000);
        }

        return [$addr, static fn () => native_stop($process)];
    }
    // The cache needs APCu for the CLI, which swerve sets for its workers: docs/embedding.md
    $process = proc_open(
        [PHP_BINARY, '-d', "extension=$ext", '-d', 'apc.enable_cli=1', __DIR__ . '/Fixtures/parity/server.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $addr = trim((string) fgets($pipes[1]));
    if ('' === $addr) {
        throw new RuntimeException('The Swerve\Server fixture did not start');
    }

    return [$addr, static function () use ($process, $pipes) {
        fwrite($pipes[0], "close\n");
        stream_get_contents($pipes[1]);
        proc_close($process);
    }];
}

/** One request on a fresh connection: the response. */
function parity_request(string $addr, string $head, string $body = ''): array
{
    $conn = native_connect($addr);
    fwrite($conn, $head . "\r\n" . $body);
    $response = native_read_response($conn);
    fclose($conn);

    return $response ?? throw new RuntimeException('No response');
}

/** A body's JSON from a GET. */
function parity_json(string $addr, string $path): mixed
{
    return json_decode(parity_request($addr, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'], true);
}

dataset('modes', ['swerve', 'server']);

test('a request: superglobals, PHP_SAPI, header(), http_response_code(), setcookie(), its own Scope', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $response = parity_request($addr, "GET /request?x=1 HTTP/1.1\r\nHost: test\r\nCookie: a=b\r\nConnection: close\r\n");
        expect($response['status'])->toBe(201);
        expect($response['headers']['x-parity'] ?? null)->toBe('yes');
        expect($response['headers']['set-cookie'] ?? null)->toBe('c=v');
        expect(json_decode($response['body'], true))->toBe(['swerve', 'GET', '/request?x=1', 'x=1', 'HTTP/1.1', 'test', true, ['x' => '1'], ['a' => 'b'], true]);
    } finally {
        $stop();
    }
})->with('modes');

test('a POST: $_POST and php://input', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $form = parity_request($addr, "POST /post HTTP/1.1\r\nHost: test\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 7\r\nConnection: close\r\n", 'a=1&b=2');
        expect(json_decode($form['body'], true))->toBe([['a' => '1', 'b' => '2'], 'a=1&b=2', 'application/x-www-form-urlencoded']);
        $json = parity_request($addr, "POST /post HTTP/1.1\r\nHost: test\r\nContent-Type: application/json\r\nContent-Length: 7\r\nConnection: close\r\n", '{"a":1}');
        expect(json_decode($json['body'], true))->toBe([[], '{"a":1}', 'application/json']);
    } finally {
        $stop();
    }
})->with('modes');

test('a session lasts across requests', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $first = parity_request($addr, "GET /session HTTP/1.1\r\nHost: test\r\nConnection: close\r\n");
        expect($first['body'])->toBe('1');
        $cookie = strtok($first['headers']['set-cookie'] ?? '', ';');
        expect($cookie)->toStartWith('PHPSESSID=');
        expect(parity_request($addr, "GET /session HTTP/1.1\r\nHost: test\r\nCookie: $cookie\r\nConnection: close\r\n")['body'])->toBe('2');
    } finally {
        $stop();
    }
})->with('modes');

test('two requests at once keep their own superglobals across a wait', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $a = native_connect($addr);
        $b = native_connect($addr);
        fwrite($a, "GET /isolation?id=a HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        fwrite($b, "GET /isolation?id=b HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        expect(json_decode(native_read_response($a)['body'], true))->toBe(['a', 'a', 'id=a']);
        expect(json_decode(native_read_response($b)['body'], true))->toBe(['b', 'b', 'id=b']);
    } finally {
        $stop();
    }
})->with('modes');

test('flush() streams a chunked body, and a connection is kept alive across requests', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $conn = native_connect($addr);
        for ($i = 0; $i < 3; ++$i) {
            fwrite($conn, "GET /stream HTTP/1.1\r\nHost: test\r\n\r\n");
            $response = native_read_response($conn);
            expect([$response['headers']['transfer-encoding'] ?? null, $response['body']])->toBe(['chunked', 'ab']);
        }
    } finally {
        $stop();
    }
})->with('modes');

test('Swerve::finishRequest() answers, and the request runs on with connection_aborted() true', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        expect(parity_request($addr, "GET /finish HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'])->toBe('answered');
        usleep(300000);
        expect(parity_request($addr, "GET /tail HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'])->toBe('tail ran, aborted=1');
    } finally {
        $stop();
    }
})->with('modes');

test('publish() and subscribe(), cache(), container(), draining(), getVersion() and log()', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        expect(parity_json($addr, '/pubsub'))->toBe(['m' => 1]);
        expect(parity_json($addr, '/cache'))->toBe([['v' => 1], false]);
        expect(parity_json($addr, '/container'))->toBe(['made']);
        expect(parity_json($addr, '/state'))->toBe([false, true, true]);
    } finally {
        $stop();
    }
})->with('modes');

test('accept() inside a request throws a LogicException', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        expect(parity_request($addr, "GET /accept-inside HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'])->toBe('LogicException');
    } finally {
        $stop();
    }
})->with('modes');

test('a handler that throws is a 500, and serving goes on', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        expect(parity_request($addr, "GET /throws HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['status'])->toBe(500);
        expect(parity_json($addr, '/state'))->toBe([false, true, true]);
    } finally {
        $stop();
    }
})->with('modes');

// exit() ends the PHP program that runs the request, its response finished first: under swerve
// that is the worker's run of swerve.php, which the master starts again; under a Swerve\Server,
// the program itself, as in any CLI script (docs/embedding.md)
test('exit() finishes its response and ends the program that runs it', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $exit = parity_request($addr, "GET /exit HTTP/1.1\r\nHost: test\r\nConnection: close\r\n");
        expect([$exit['status'], $exit['body']])->toBe([200, 'before exit']);
        usleep(200000);
        expect(probe($addr, '/file.txt'))->toBe('swerve' === $mode ? 'static-body' : null);
    } finally {
        $stop();
    }
})->with('modes');

test('static files, and what is no file goes to the application', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        expect(parity_request($addr, "GET /file.txt HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'])->toBe('static-body');
        $missing = parity_request($addr, "GET /missing.txt HTTP/1.1\r\nHost: test\r\nConnection: close\r\n");
        expect([$missing['status'], $missing['body']])->toBe([404, 'no route']);
    } finally {
        $stop();
    }
})->with('modes');

test('upgrade(): Server-Sent Events, a WebSocket and the raw ClientRequest', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    try {
        $sse = native_connect($addr);
        fwrite($sse, "GET /sse HTTP/1.1\r\nHost: test\r\n\r\n");
        $events = read_until($sse, "data: two\n\n");
        expect($events)->toContain("data: one\n\n");
        expect($events)->toContain("event: named\ndata: two\n\n");

        $ws = ws_connect($addr);
        ws_send($ws, 1, 'hi');
        expect(ws_read($ws))->toBe([1, 'echo hi']);

        expect(parity_request($addr, "GET /raw HTTP/1.1\r\nHost: test\r\nConnection: close\r\n")['body'])->toBe('raw GET');
    } finally {
        $stop();
    }
})->with('modes');

test('stopping: onShutdown() runs, awaitShutdown() returns, and draining() is true', function (string $mode) {
    [$addr, $stop] = parity_start($mode);
    $ws  = ws_connect($addr);
    $sse = native_connect($addr);
    fwrite($sse, "GET /wait-shutdown HTTP/1.1\r\nHost: test\r\n\r\n");
    read_until($sse, "data: waiting\n\n");
    $stop();
    expect(ws_read($ws))->toBe([8, pack('n', 1001) . 'bye']);
    expect(read_until($sse, 'draining=true'))->toContain('data: awaitShutdown=true draining=true');
})->with('modes');
