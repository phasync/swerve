<?php

/*
 * Swerve\Http\Virtual: code written for PHP-FPM, run as requests of their own with phasync-ext's
 * virtualize(). Needs the extension: PHASYNC_EXT=/path/to/phasync.so vendor/bin/pest
 */

function virtual_start(): array
{
    return swerve_start(workers: 1, php: ['-d', 'extension=' . getenv('PHASYNC_EXT')]);
}

test('Virtual: echo, status, headers, cookies, the session and php://input become the response', function () {
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
})->skip(fn () => !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Virtual: concurrent requests in one worker keep their own session, and exit() ends only its request', function () {
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
})->skip(fn () => !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');

test('Virtual: concurrent requests each have their own $_GET, $_COOKIE, $_SERVER and $_POST, also after waiting', function () {
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
})->skip(fn () => !getenv('PHASYNC_EXT'), 'needs PHASYNC_EXT=/path/to/phasync.so');
