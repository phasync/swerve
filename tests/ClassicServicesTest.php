<?php

/*
 * Swerve's cache and publish/subscribe in Classic scripts, which run outside the workers (the
 * request server's processes with ext-phasync, php -S without): through the worker's service
 * socket (ServiceServer), with the client prepended to every script.
 */

/** The body of a GET, chunked or not. */
function services_get(string $addr, string $path, string $headers = ''): string
{
    $conn = stream_socket_client("tcp://$addr");
    fwrite($conn, "GET $path HTTP/1.1\r\nHost: t\r\n{$headers}Connection: close\r\n\r\n");
    $response    = (string) stream_get_contents($conn);
    [$head, $rest] = explode("\r\n\r\n", $response, 2);
    if (!preg_match('/^transfer-encoding: chunked/mi', $head)) {
        return $rest;
    }
    $body = '';
    while ('0' !== ($size = substr($rest, 0, strpos($rest, "\r\n")))) {
        $rest  = substr($rest, strlen($size) + 2);
        $body .= substr($rest, 0, hexdec($size));
        $rest  = substr($rest, hexdec($size) + 2);
    }

    return $body;
}

function services_start(): string
{
    [, $addr] = swerve_start(['--classic-workers=4'], workers: 1, fixture: __DIR__ . '/Fixtures/classic-services/swerve.php');

    return $addr;
}

it('stores and reads cache values from scripts, the same cache the workers have', function () {
    $addr = services_start();
    expect(services_get($addr, '/set?k=greeting&v=hi'))->toBe('true')
        ->and(services_get($addr, '/get?k=greeting'))->toBe('"hi"')
        ->and(services_get($addr, '/get?k=missing'))->toBe('"default"')
        ->and(services_get($addr, '/worker/get?k=greeting'))->toBe('"hi"')     // an async route, in the worker
        ->and(services_get($addr, '/set-object'))->toBe('ok')
        ->and(services_get($addr, '/get-object'))->toBe('2026-10-23 12:00')
        ->and(services_get($addr, '/bad-key'))->toBe('Swerve\Classic\CacheKeyException');
});

it('delivers a message a script publishes to a script that subscribes', function () {
    $addr = services_start();
    $sub  = stream_socket_client("tcp://$addr");
    stream_set_timeout($sub, 10);
    fwrite($sub, "GET /subscribe HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    $seen = '';
    while (!str_contains($seen, 'subscribed')) {
        $seen .= fread($sub, 8192);
    }
    expect(services_get($addr, '/publish?h=extra'))->toBe('published');
    $seen .= stream_get_contents($sub);
    expect($seen)->toContain('{"headline":"extra"}');
});

it('hides the private header from the application, and a client can\'t send one', function () {
    $addr = services_start();
    expect(services_get($addr, '/header', "X-Swerve-Services: /tmp/evil.sock\r\n"))->toBe('false');
});

it('hands a script\'s client over to the worker: a WebSocket its closure runs there', function () {
    $addr = services_start();
    $ws   = ws_connect($addr, '/ws');
    ws_send($ws, 0x1, 'hi');
    expect(ws_read($ws))->toBe([0x1, 'echo: hi']);
    ws_send($ws, 0x1, 'again');
    expect(ws_read($ws))->toBe([0x1, 'echo: again']);
});

it('hands a script\'s client over to the worker: an event stream', function () {
    $addr = services_start();
    $conn = stream_socket_client("tcp://$addr");
    fwrite($conn, "GET /sse HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    $response = (string) stream_get_contents($conn);
    expect($response)->toContain("content-type: text/event-stream")
        ->and($response)->toContain("data: one\n\n")
        ->and($response)->toContain("data: two\n\n")
        ->and($response)->not->toContain('discarded');
});

it('refuses a closure that isn\'t static', function () {
    expect(services_get(services_start(), '/not-static'))->toContain('must be static');
});
