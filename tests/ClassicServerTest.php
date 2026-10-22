<?php

/*
 * Classic mode end to end, over real HTTP, against ext-phasync's request server (MODES.md's
 * "How Classic runs"): routing, per-request state, request bodies, streaming, response framing,
 * crashes, and the request server's process model and supervision.
 *
 * The whole file needs ext-phasync's request server: phasync.serve_max_children is a real ini
 * setting only when the loaded extension build has it. Without it (extension not loaded, or an
 * older ext build), every test skips with that reason.
 *
 * tests/Fixtures/Classic/swerve.php serves tests/Fixtures/Classic/public with ClassicMode, its
 * router at public/router.php (kept apart from public/index.php, so the directory-index and
 * router-fallback behaviours are tested independently), and a `/hello` async route (never
 * touching the Classic backend) for swerve_start()'s readiness probe.
 */

function cs_ext_available(): bool
{
    return \extension_loaded('phasync') && false !== \ini_get('phasync.serve_max_children');
}

const CS_SKIP = "needs ext-phasync's request server (phasync.serve_max_children not available)";

/**
 * Start swerve serving the Classic fixture. $dir (SWERVE_TEST_DIR, for fixtures that need a
 * shared file) is created fresh unless given.
 *
 * @return array{0: resource, 1: string, 2: string, 3: int, 4: string} process, address, log, master pid, SWERVE_TEST_DIR
 */
function cs_start(array $args = [], int $workers = 2, ?string $dir = null, bool $wait = true): array
{
    $dir ??= temp_path(dir: true);
    [$process, $addr, $log, $pid] = swerve_start($args, $workers, env: ['SWERVE_TEST_DIR' => $dir], fixture: __DIR__ . '/Fixtures/Classic/swerve.php', wait: $wait);

    return [$process, $addr, $log, $pid, $dir];
}

/** One request on a fresh connection, closed after; $head for a HEAD request (no body expected). */
function cs_request(string $addr, string $method, string $target, string $headers = '', string $body = '', bool $head = false): ?array
{
    $conn     = native_connect($addr);
    $response = cs_request_on($conn, $method, $target, $headers, $body, close: true, head: $head);
    fclose($conn);

    return $response;
}

/** One request on an existing connection, kept open unless $close. */
function cs_request_on($conn, string $method, string $target, string $headers = '', string $body = '', bool $close = false, bool $head = false): ?array
{
    $contentLength = '' === $body ? '' : 'Content-Length: ' . \strlen($body) . "\r\n";
    $connection    = $close ? "Connection: close\r\n" : '';
    \fwrite($conn, "$method $target HTTP/1.1\r\nHost: test\r\n{$headers}{$contentLength}{$connection}\r\n$body");

    return native_read_response($conn, $head);
}

/** The pids whose parent is $ppid (reading /proc), live only. */
function cs_children(int $ppid): array
{
    $out = [];
    \set_error_handler(static fn (): bool => true); // a process may end while it is read
    foreach (\glob('/proc/[0-9]*/stat') as $file) {
        $stat = \file_get_contents($file);
        if (false === $stat) {
            continue;
        }
        $fields = \explode(' ', \substr($stat, \strrpos($stat, ')') + 2));
        if (($fields[0] ?? '') !== 'Z' && (int) ($fields[1] ?? -1) === $ppid) {
            $out[] = (int) \basename(\dirname($file));
        }
    }
    \restore_error_handler();

    return $out;
}

function cs_cmdline(int $pid): string
{
    return (string) @\file_get_contents("/proc/$pid/cmdline");
}

/** The request server's own pid: the master's child whose cmdline carries phasync.serve=. */
function cs_server_pid(int $masterPid, float $timeout = 5): int
{
    $deadline = \microtime(true) + $timeout;
    while (true) {
        foreach (cs_children($masterPid) as $candidate) {
            if (\str_contains(cs_cmdline($candidate), 'phasync.serve=')) {
                return $candidate;
            }
        }
        if (\microtime(true) > $deadline) {
            throw new \RuntimeException("No request server among master $masterPid's children within {$timeout}s");
        }
        \usleep(20000);
    }
}

/** [current, max] from a cs_start() fixture's counter file (see public/concurrent.php). */
function cs_counter_read(string $dir): array
{
    $data = \trim((string) @\file_get_contents("$dir/counter")) ?: '0 0';

    return \array_map('intval', \explode(' ', $data));
}

/** Send a GET and leave it in flight, without reading the response. */
function cs_send_get(string $addr, string $path)
{
    $conn = native_connect($addr);
    \fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\n\r\n");

    return $conn;
}

/** Whether a pid is still alive (not gone, not a zombie). */
function cs_alive(int $pid): bool
{
    \set_error_handler(static fn (): bool => true); // it may end while it is read
    try {
        $stat = \file_get_contents("/proc/$pid/stat");
    } finally {
        \restore_error_handler();
    }

    return false !== $stat && !\str_contains($stat, ') Z ');
}

/** $n concurrent requests over $n distinct connections, returned in the order sent. */
function cs_concurrent(string $addr, string $target, int $n): array
{
    $conns = [];
    for ($i = 0; $i < $n; ++$i) {
        $conns[] = cs_send_get($addr, $target);
    }
    $responses = [];
    foreach ($conns as $conn) {
        $responses[] = native_read_response($conn);
        \fclose($conn);
    }

    return $responses;
}

/*
 * 1. Routing and static files
 */

test('an existing .php file runs, with the rest of the path as PATH_INFO', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/script.php/extra/path');
    expect($r['status'])->toBe(200);
    expect(\json_decode($r['body'], true))->toBe(['path_info' => '/extra/path', 'script_name' => '/script.php', 'php_self' => '/script.php/extra/path']);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a directory with a trailing slash runs its index.php', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/sub/');
    expect($r['status'])->toBe(200);
    expect(\json_decode($r['body'], true))->toBe(['script_name' => '/sub/index.php', 'request_uri' => '/sub/']);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a directory without a trailing slash redirects to it, keeping the query string', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/sub?x=1&y=two');
    expect($r['status'])->toBe(301);
    expect($r['headers']['location'])->toBe('/sub/?x=1&y=two');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('an unmatched path runs the router', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/no/such/path');
    expect($r['status'])->toBe(404);
    expect($r['body'])->toBe('ROUTER:/no/such/path');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a static file is served by Swerve itself, with an ETag, not run or forwarded to the backend', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/static.txt');
    expect($r['status'])->toBe(200);
    expect($r['body'])->toBe("static-content-123\n");
    expect($r['headers'])->toHaveKey('etag');
    expect($r['headers'])->toHaveKey('last-modified');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a dotfile and a .inc file are never served or executed: both fall through to the router', function (string $path) {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', $path);
    expect($r['status'])->toBe(404);
    expect($r['body'])->toBe("ROUTER:$path");
    native_stop($process);
})->with(['dotfile' => '/.env', '.inc source' => '/secret.inc'])->skip(!cs_ext_available(), CS_SKIP);

test('a path that escapes the public root is a 400, never reaching the router or the backend', function () {
    [$process, $addr] = cs_start();
    $conn              = native_connect($addr);
    \fwrite($conn, "GET /../../../../etc/passwd HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $r = native_read_response($conn);
    \fclose($conn);
    expect($r['status'])->toBe(400);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('$_SERVER is filled as PHP-FPM behind nginx would, and PHP_SAPI is swerve', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/server-vars.php/extra?x=1');
    $body              = \json_decode($r['body'], true);
    $publicRoot        = \realpath(__DIR__ . '/Fixtures/Classic/public');
    expect($body)->toBe([
        'sapi'            => 'swerve',
        'script_name'     => '/server-vars.php',
        'script_filename' => "$publicRoot/server-vars.php",
        'php_self'        => '/server-vars.php/extra',
        'path_info'       => '/extra',
        'document_root'   => $publicRoot,
        'request_uri'     => '/server-vars.php/extra?x=1',
        'query_string'    => 'x=1',
        'remote_addr'     => '127.0.0.1',
        'http_host'       => 'test',
    ]);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 2. Fresh state per request; header()/http_response_code()/Set-Cookie; exit(); fatal errors
 */

test('a static class property and a function-local static reset every request, even on a reused child process', function () {
    [$process, $addr] = cs_start(['--classic-workers=1'], workers: 1);
    $conn              = native_connect($addr);
    $seen              = [];
    for ($i = 0; $i < 3; ++$i) {
        $r      = cs_request_on($conn, 'GET', '/counter.php', close: 2 === $i);
        $seen[] = \json_decode($r['body'], true);
    }
    \fclose($conn);
    expect($seen)->toBe([
        ['static_prop' => 1, 'static_local' => 1],
        ['static_prop' => 1, 'static_local' => 1],
        ['static_prop' => 1, 'static_local' => 1],
    ]);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('header(), http_response_code() and several Set-Cookie headers reach the client', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/headers.php');
    expect($r['status'])->toBe(201);
    expect($r['headers']['x-custom'])->toBe('yes');
    expect($r['cookies'])->toBe(['a=1', 'b=2']);
    expect($r['body'])->toBe('created');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('exit() right after header() and http_response_code() keeps the status, with no body', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/redirect-exit.php');
    expect($r['status'])->toBe(302);
    expect($r['headers']['location'])->toBe('/elsewhere');
    expect($r['body'])->toBe('');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a fatal error with display_errors off answers 500 with no error text, and the next request on the pool still works', function () {
    [$process, $addr] = cs_start();
    $first              = cs_request($addr, 'GET', '/fatal.php');
    expect($first['status'])->toBe(500);
    expect($first['body'])->not->toContain('Fatal error')->not->toContain('nonexistent_function_call_xyz');
    $second = cs_request($addr, 'GET', '/ok.php');
    expect($second['status'])->toBe(200);
    expect($second['body'])->toBe('still working');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 3. Request bodies
 */

test('a POST form body reaches $_POST', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'POST', '/post.php', "Content-Type: application/x-www-form-urlencoded\r\n", 'a=1&b=two');
    expect($r['status'])->toBe(200);
    expect(\json_decode($r['body'], true))->toBe(['a' => '1', 'b' => 'two']);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a multipart upload reaches $_FILES with its content intact', function () {
    [$process, $addr] = cs_start();
    $boundary          = '----CsTestBoundary';
    $content           = \random_bytes(500); // binary, to catch any text-mode mangling
    $body              = "--$boundary\r\nContent-Disposition: form-data; name=\"upload\"; filename=\"a.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n--$boundary--\r\n";
    $r                 = cs_request($addr, 'POST', '/upload.php', "Content-Type: multipart/form-data; boundary=$boundary\r\n", $body);
    expect($r['status'])->toBe(200);
    $parsed = \json_decode($r['body'], true);
    expect($parsed['upload']['name'])->toBe('a.bin');
    expect($parsed['upload']['size'])->toBe(500);
    expect(\base64_decode($parsed['upload']['content_b64']))->toBe($content);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a script that never reads the body (STOP) still lets the next request on the same connection through', function () {
    [$process, $addr] = cs_start();
    $conn              = native_connect($addr);
    $r1                = cs_request_on($conn, 'POST', '/ignore-body.php', '', \str_repeat('x', 50000));
    expect($r1['status'])->toBe(200);
    expect($r1['body'])->toBe('ignored-body');
    $r2 = cs_request_on($conn, 'GET', '/pid.php', close: true);
    expect($r2['status'])->toBe(200);
    expect((int) $r2['body'])->toBeGreaterThan(0);
    \fclose($conn);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a several-MB request body is read in full through php://input', function () {
    [$process, $addr] = cs_start();
    $body              = \random_bytes(4 * 1024 * 1024);
    $r                 = cs_request($addr, 'POST', '/big-body.php', "Content-Type: application/octet-stream\r\n", $body);
    expect($r['status'])->toBe(200);
    expect((int) $r['body'])->toBe(\strlen($body));
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 4. Streaming
 */

test('flush() delivers the first part to the client well before the second', function () {
    [$process, $addr] = cs_start();
    $conn              = native_connect($addr);
    \fwrite($conn, "GET /stream.php HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $start = \microtime(true);
    $head  = native_read_head($conn);
    expect($head['status'])->toBe(200);

    \stream_set_blocking($conn, false);
    $buf = '';
    while (!\str_contains($buf, 'first-part')) {
        $r = [$conn];
        $w = $e = null;
        if (\stream_select($r, $w, $e, 0, 50000)) {
            $buf .= \fread($conn, 65536);
        }
        expect(\microtime(true) - $start)->toBeLessThan(2.0); // guards against hanging forever
    }
    $firstSeenAfter = \microtime(true) - $start;
    \stream_set_blocking($conn, true);
    $rest = \stream_get_contents($conn);
    \fclose($conn);

    // Well under the 300 ms the script sleeps between its two echoes
    expect($firstSeenAfter)->toBeLessThan(0.2);
    expect($buf . $rest)->toContain('second-part');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 5. Response framing
 */

test('a response that arrives whole gets a Content-Length', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/small.php');
    expect($r['status'])->toBe(200);
    expect($r['headers']['content-length'] ?? null)->toBe('5');
    expect($r['headers']['transfer-encoding'] ?? null)->toBeNull();
    expect($r['body'])->toBe('hello');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a streamed response is chunked', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/stream.php');
    expect($r['status'])->toBe(200);
    expect(\strtolower($r['headers']['transfer-encoding'] ?? ''))->toBe('chunked');
    expect($r['complete'])->toBeTrue();
    expect($r['body'])->toBe('first-partsecond-part');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('HEAD has no body', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'HEAD', '/small.php', head: true);
    expect($r['status'])->toBe(200);
    expect($r['body'])->toBe('');
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 6. Crashes
 */

test('a script that SIGKILLs itself before any output answers 502, and the next request still works', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/selfkill.php');
    expect($r['status'])->toBe(502);
    $next = cs_request($addr, 'GET', '/pid.php');
    expect($next['status'])->toBe(200);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('a script that SIGKILLs itself after flushing output ends the client connection early, and the next request still works', function () {
    [$process, $addr] = cs_start();
    $r                 = cs_request($addr, 'GET', '/selfkill-after.php');
    expect($r['body'])->toContain('partial');
    expect($r['complete'])->toBeFalse();
    $next = cs_request($addr, 'GET', '/pid.php');
    expect($next['status'])->toBe(200);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 7. Process model
 */

test('the request server has no children before the first request', function () {
    [$process, $addr, , $pid] = cs_start();
    $serverPid                = cs_server_pid($pid);
    expect(cs_children($serverPid))->toBe([]);
    expect(cs_request($addr, 'GET', '/pid.php')['status'])->toBe(200);
    native_stop($process);
});

test('--classic-workers caps concurrent scripts at that many, while still serving every request', function () {
    [$process, $addr, , , $dir] = cs_start(['--classic-workers=2']);
    $responses                   = cs_concurrent($addr, '/concurrent.php', 4);
    foreach ($responses as $r) {
        expect($r['status'])->toBe(200);
        expect($r['body'])->toBe('done');
    }
    [$current, $max] = cs_counter_read($dir);
    expect($current)->toBe(0); // every request finished and decremented back
    expect($max)->toBeLessThanOrEqual(2);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('requests from different swerve workers share the one queue: none waits for an idle child', function () {
    [$process, $addr] = cs_start(['--classic-workers=4'], workers: 2);
    $start             = \microtime(true);
    $responses         = cs_concurrent($addr, '/concurrent.php', 8);
    $elapsed           = \microtime(true) - $start;
    foreach ($responses as $r) {
        expect($r['status'])->toBe(200);
    }
    // 2 rounds of 500 ms (8 requests, 4 slots) if the queue is shared; a per-worker split (an
    // earlier design, see MODES.md) would starve one worker's requests behind its own smaller
    // share while the other worker's children sit idle, taking longer.
    expect($elapsed)->toBeLessThan(1.3);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * 8. Supervision
 */

test('the master restarts the request server if it is killed, and requests work again', function () {
    [$process, $addr, $log, $pid] = cs_start();
    $serverPid                    = cs_server_pid($pid);
    \posix_kill($serverPid, \SIGKILL);
    log_wait($log, "/The request server $serverPid died \\(signal 9\\).*starting it again/", 5);

    $deadline = \microtime(true) + 5;
    while (null === probe($addr, '/pid.php', 0.5)) {
        expect(\microtime(true))->toBeLessThan($deadline);
    }
    $newServerPid = cs_server_pid($pid);
    expect($newServerPid)->not->toBe($serverPid);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

test('SIGTERM stops the request server and its children, leaving nothing in the process group', function () {
    [$process, $addr, , $pid, $dir] = cs_start();
    cs_server_pid($pid); // make sure it exists before we rely on it being gone
    $inFlight = cs_send_get($addr, '/concurrent.php'); // forks a backend child, left mid-flight
    \usleep(100000);
    swerve_signal($process, \SIGTERM);
    swerve_wait($process, 5);
    expect(group_gone($pid))->toBeTrue();
    \fclose($inFlight);
})->skip(!cs_ext_available(), CS_SKIP);

test('SIGHUP replaces the request server: the old one is gone, a new one serves', function () {
    [$process, $addr, $log, $pid] = cs_start();
    $oldServerPid                 = cs_server_pid($pid);
    swerve_signal($process, \SIGHUP);
    log_wait($log, '/Reload complete/', 8);

    expect(cs_alive($oldServerPid))->toBeFalse();
    $newServerPid = cs_server_pid($pid, 5);
    expect($newServerPid)->not->toBe($oldServerPid);
    expect(cs_request($addr, 'GET', '/pid.php')['status'])->toBe(200);
    native_stop($process);
})->skip(!cs_ext_available(), CS_SKIP);

/*
 * Skip check without the extension (or an ext build without Classic serve support): the whole
 * file must skip cleanly, which the per-test ->skip() above already does for every test. This
 * one documents the check itself and runs regardless, so a run without the extension still
 * shows at least one (skipped) assertion about why.
 */
test('the file needs the extension\'s request server, and skips cleanly without it', function () {
    expect(cs_ext_available())->toBeFalse();
})->skip(cs_ext_available(), 'the extension is loaded: the real tests above run instead');
