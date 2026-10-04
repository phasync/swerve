<?php

/*
 * Swerve::virtualize(): code written for PHP-FPM, run as requests of their own with phasync-ext's
 * virtualize(), its output streaming to the client. Needs the extension: PHASYNC_EXT=/path/to/phasync.so vendor/bin/pest,
 * or run the suite with the extension loaded (PHP_INI_SCAN_DIR).
 */

use Swerve\Http\Virtual;

function virtual_start(): array
{
    return swerve_start(workers: 1, fixture: 'virtual.php', php: Virtual::available() ? [] : ['-d', 'extension=' . getenv('PHASYNC_EXT')]);
}

test('Swerve::virtualize(): echo, status, headers, cookies, the session and php://input become the response', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "POST /virtual HTTP/1.1\r\nHost: t\r\nContent-Type: text/plain\r\nContent-Length: 5\r\n\r\nhello");
        $response = native_read_response($conn);
        expect($response['status'])->toBe(201);
        expect($response['headers']['x-virtual'])->toBe('yes');
        expect($response['body'])->toStartWith('n=1 body=hello sid=')->toEndWith("last\n");
        preg_match('/sid=(\w+)/', $response['body'], $m);

        // The session carries over with its cookie
        $conn = native_connect($addr);
        fwrite($conn, "GET /virtual HTTP/1.1\r\nHost: t\r\nCookie: PHPSESSID={$m[1]}\r\n\r\n");
        expect(native_read_response($conn)['body'])->toStartWith("n=2 body= sid={$m[1]}");
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): concurrent requests in one worker keep their own session, and exit() ends only its request', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conns = [];
        for ($i = 0; $i < 6; ++$i) {
            $conns[$i] = native_connect($addr);
            fwrite($conns[$i], 'GET /virtual?ms=300' . (0 === $i % 3 ? '&exit=1' : '') . " HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        $sids = [];
        foreach ($conns as $i => $conn) {
            $body = native_read_response($conn)['body'];
            expect($body)->toStartWith('n=1 body= sid=');
            expect(str_ends_with($body, "last\n"))->toBe(0 !== $i % 3); // those that exited end after the first chunk
            preg_match('/sid=(\w+)/', $body, $m);
            $sids[] = $m[1];
        }
        expect(count(array_unique($sids)))->toBe(6);
        expect(probe($addr, '/hello'))->toBe('Hello'); // the worker lives on
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): concurrent requests each have their own $_SESSION while they wait, and each session saves its own', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conns = [];
        foreach (['a', 'b', 'c'] as $v) {
            $conns[$v] = native_connect($addr);
            fwrite($conns[$v], "GET /virtual-session?v=$v&ms=200 HTTP/1.1\r\nHost: t\r\n\r\n");
        }
        $sids = [];
        foreach ($conns as $v => $conn) {
            [$sid, $read, $after] = explode(' ', native_read_response($conn)['body']);
            expect([$read, $after])->toBe([$v, $v]);
            $sids[$v] = $sid;
        }
        foreach ($sids as $v => $sid) { // what each session saved
            $conn = native_connect($addr);
            fwrite($conn, "GET /virtual-session HTTP/1.1\r\nHost: t\r\nCookie: PHPSESSID=$sid\r\n\r\n");
            expect(native_read_response($conn)['body'])->toBe("$sid $v $v");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): concurrent requests each have their own $_GET, $_COOKIE, $_SERVER and $_POST, also after waiting', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conns = [];
        for ($i = 0; $i < 8; ++$i) {
            $conns[$i] = native_connect($addr);
            $body      = "p=post$i";
            fwrite($conns[$i], "POST /virtual-globals?q=get$i&ms=200 HTTP/1.1\r\nHost: t\r\nCookie: c=cookie$i\r\nX-T: header$i\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
        }
        foreach ($conns as $i => $conn) {
            $own = "get$i|cookie$i|header$i|post$i";
            expect(native_read_response($conn)['body'])->toBe("$own $own");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): output streams as it is made, in chunks, and keep-alive carries on', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /virtual-stream?n=3&ms=300 HTTP/1.1\r\nHost: t\r\n\r\n");
        $start = microtime(true);
        $head  = '';
        while (!str_contains($head, "piece1\n")) {
            $head .= fread($conn, 4096);
        }
        expect(microtime(true) - $start)->toBeLessThan(0.25); // the first piece did not wait for the others
        expect($head)->toContain('Transfer-Encoding: chunked');
        stream_set_blocking($conn, true);
        $rest = '';
        while (!str_ends_with($rest, "0\r\n\r\n")) {
            $rest .= fread($conn, 4096);
        }
        expect($rest)->toContain("piece2\n")->toContain("piece3\n");
        // The same connection serves the next request
        fwrite($conn, "GET /hello HTTP/1.1\r\nHost: t\r\n\r\n");
        expect(native_read_response($conn)['body'])->toBe('Hello');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): the application\'s Content-Length, HEAD, a redirect with exit, and a 404', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /virtual-length HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect($response['headers']['content-length'])->toBe('5');
        expect($response['body'])->toBe('hello');

        fwrite($conn, "HEAD /virtual-length HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn, head: true);
        expect($response['headers']['content-length'])->toBe('5');
        expect($response['body'])->toBe('');

        fwrite($conn, "GET /virtual-redirect HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect($response['status'])->toBe(302);
        expect($response['headers']['location'])->toBe('/hello');

        fwrite($conn, "GET /nothing HTTP/1.1\r\nHost: t\r\n\r\n");
        $response = native_read_response($conn);
        expect($response['status'])->toBe(404);
        expect($response['body'])->toBe('Not found');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize() over FastCGI: echo, status, headers and the body become the response, several requests multiplexed on one connection', function () {
    [$process, $addr, $log] = swerve_start(workers: 1, fixture: 'virtual.php', mode: 'fastcgi', php: Virtual::available() ? [] : ['-d', 'extension=' . getenv('PHASYNC_EXT')]);
    try {
        $conn  = fcgi_connect($addr);
        $start = microtime(true);
        fwrite($conn, fcgi_request(1, 'POST', '/virtual?ms=300', 'hello') . fcgi_request(2, 'GET', '/virtual?ms=300&exit=1') . fcgi_request(3, 'GET', '/virtual-length') . fcgi_request(4, 'GET', '/nowhere'));
        $responses = fcgi_read_responses($conn, [1, 2, 3, 4]);

        expect(microtime(true) - $start)->toBeLessThan(0.55); // the two waits overlapped
        expect($responses[1]['status'])->toBe(201);
        expect($responses[1]['headers']['x-virtual'])->toBe('yes');
        expect($responses[1]['body'])->toStartWith('n=1 body=hello sid=')->toEndWith("last\n");
        expect($responses[2]['body'])->toStartWith('n=1 body= sid=')->not->toEndWith("last\n");
        expect($responses[3]['body'])->toBe('hello');
        expect($responses[4]['status'])->toBe(404);
        expect($responses[4]['body'])->toBe('Not found');
        foreach ($responses as $response) {
            expect([$response['appStatus'], $response['protocolStatus']])->toBe([0, 0]);
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Swerve::virtualize(): a form is read by PHP, and the PSR request has the same $_POST and uploaded files; other bodies stay in the PSR request', function () {
    [$process, $addr, $log] = virtual_start();
    try {
        $conn = native_connect($addr);
        fwrite($conn, "POST /virtual-form HTTP/1.1\r\nHost: t\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 11\r\n\r\na=1&b[]=two");
        $body = json_decode(native_read_response($conn)['body'], true);
        expect($body['post'])->toBe(['a' => '1', 'b' => ['two']]);
        expect($body['parsed'])->toBe(['a' => '1', 'b' => ['two']]);

        $multipart = "--X\r\nContent-Disposition: form-data; name=\"t\"\r\n\r\nvalue\r\n--X\r\nContent-Disposition: form-data; name=\"f[]\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nfile body\r\n--X--\r\n";
        $conn      = native_connect($addr);
        fwrite($conn, "POST /virtual-form HTTP/1.1\r\nHost: t\r\nContent-Type: multipart/form-data; boundary=X\r\nContent-Length: " . strlen($multipart) . "\r\n\r\n$multipart");
        $body = json_decode(native_read_response($conn)['body'], true);
        expect($body['post'])->toBe(['t' => 'value']);
        expect($body['parsed'])->toBe(['t' => 'value']);
        expect($body['files'])->toBe(['f' => ['a.txt']]);
        expect($body['psr'])->toBe(['f' => ['a.txt:9:file body']]);

        $conn = native_connect($addr);
        fwrite($conn, "POST /virtual-form HTTP/1.1\r\nHost: t\r\nContent-Type: application/json\r\nContent-Length: 8\r\n\r\n{\"a\":42}");
        $body = json_decode(native_read_response($conn)['body'], true);
        expect($body['input'])->toBe('{"a":42}');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(fn () => !Virtual::available() && !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');
