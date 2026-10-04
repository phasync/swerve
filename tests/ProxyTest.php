<?php

/*
 * --trusted-proxy: what a proxy in front says about the client (X-Forwarded-For, -Proto, -Host)
 * is believed from the proxies named, and from no one else.
 */

function proxy_get(string $addr, string $headers): array
{
    $conn = native_connect($addr);
    fwrite($conn, "GET /who HTTP/1.1\r\nHost: internal:8080\r\n{$headers}Connection: close\r\n\r\n");

    return json_decode(native_read_response($conn)['body'], true);
}

test('--trusted-proxy: a trusted peer\'s X-Forwarded-For, -Proto and -Host describe the client', function () {
    [$process, $addr, $log] = swerve_start(['--trusted-proxy=127.0.0.1'], workers: 1, fixture: 'proxy.php');
    try {
        $seen = proxy_get($addr, "X-Forwarded-For: 203.0.113.9\r\nX-Forwarded-Proto: https\r\nX-Forwarded-Host: www.example.test\r\n");
        expect($seen)->toBe(['remote' => '203.0.113.9', 'https' => 'on', 'uri' => 'https://www.example.test/who', 'host' => 'www.example.test']);

        // The last address that is not itself a trusted proxy: what the client sent in front of it is only a claim
        $seen = proxy_get($addr, "X-Forwarded-For: 6.6.6.6, 203.0.113.9, 127.0.0.1\r\n");
        expect($seen['remote'])->toBe('203.0.113.9');
        expect($seen['https'])->toBeNull();
        expect($seen['uri'])->toBe('http://internal:8080/who');

        // No header, no change
        expect(proxy_get($addr, '')['remote'])->toBe('127.0.0.1');
        // A scheme that is not http or https is not believed
        expect(proxy_get($addr, "X-Forwarded-Proto: gopher\r\n")['https'])->toBeNull();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
});

test('--trusted-proxy: a CIDR range trusts its addresses; any other peer\'s headers are ignored', function () {
    [$process, $addr, $log] = swerve_start(['--trusted-proxy=127.0.0.0/8'], workers: 1, fixture: 'proxy.php');
    try {
        expect(proxy_get($addr, "X-Forwarded-For: 203.0.113.9\r\n")['remote'])->toBe('203.0.113.9');
    } finally {
        native_stop($process);
    }
    [$process, $addr, $log] = swerve_start(['--trusted-proxy=10.0.0.0/8'], workers: 1, fixture: 'proxy.php');
    try {
        $seen = proxy_get($addr, "X-Forwarded-For: 203.0.113.9\r\nX-Forwarded-Proto: https\r\nX-Forwarded-Host: www.example.test\r\n");
        expect($seen)->toBe(['remote' => '127.0.0.1', 'https' => null, 'uri' => 'http://internal:8080/who', 'host' => 'internal:8080']);
    } finally {
        native_stop($process);
    }
    [$process, $addr, $log] = swerve_start([], workers: 1, fixture: 'proxy.php');
    try {
        expect(proxy_get($addr, "X-Forwarded-For: 203.0.113.9\r\n")['remote'])->toBe('127.0.0.1'); // nothing is trusted by default
    } finally {
        native_stop($process);
    }
});

test('--trusted-proxy: an address that is not an IP or a range is refused at start', function () {
    $process = swerve_spawn(['--http=127.0.0.1:' . explode(':', free_address())[1], '--trusted-proxy=not-an-address'], 'proxy.php');
    $deadline = microtime(true) + 5;
    do {
        usleep(20000);
        $status = proc_get_status($process);
    } while ($status['running'] && microtime(true) < $deadline);
    expect($status['running'])->toBeFalse();
    expect($status['exitcode'])->toBe(2);
    native_stop($process);
});
