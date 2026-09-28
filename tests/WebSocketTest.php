<?php

/*
 * Swerve\Http\WebSocket, served by the fixture's /websocket: an echo, as a browser would use it.
 */

/**
 * Send raw bytes as one frame's head and payload, masked with $mask when given.
 */
function ws_raw($conn, int $byte0, string $payload, ?string $mask = "\x01\x02\x03\x04"): void
{
    $n = strlen($payload);
    fwrite($conn, chr($byte0) . chr((null === $mask ? 0 : 0x80) | $n) . ($mask ?? '') . (null === $mask ? $payload : $payload ^ substr(str_repeat($mask, intdiv($n, 4) + 1), 0, $n)));
}

test('WebSocket: text and binary are echoed, fragments joined, pings answered, a close returned', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'hello');
        expect(ws_read($conn))->toBe([1, 'hello']);
        ws_send($conn, 2, "\x00\xFF");
        expect(ws_read($conn))->toBe([2, "\x00\xFF"]);
        ws_send($conn, 1, 'frag', false);
        ws_send($conn, 9, 'are you there');   // a ping between fragments
        ws_send($conn, 0, 'mented', false);
        ws_send($conn, 0, ' ✓');
        expect(ws_read($conn))->toBe([10, 'are you there']);
        expect(ws_read($conn))->toBe([1, 'fragmented ✓']);
        $large = str_repeat('0123456789', 70_000); // 700 kB: a 64-bit length
        ws_send($conn, 1, $large);
        expect(ws_read($conn))->toBe([1, $large]);
        ws_send($conn, 8, pack('n', 1000) . 'done');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        expect(ws_read($conn))->toBeNull();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: the callback returning closes with 1000; throwing closes with 1011 and is logged', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'bye');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        ws_send($conn, 8, pack('n', 1000));
        expect(ws_read($conn))->toBeNull();

        $conn = ws_connect($addr, '/websocket');
        ws_send($conn, 1, 'throw');
        expect(ws_read($conn))->toBe([8, pack('n', 1011)]);
        expect(ws_read($conn))->toBeNull();
        log_wait($log, '/the WebSocket callback failed/');
    } finally {
        native_stop($process);
    }
});

test('WebSocket: a client breaking the protocol is closed with 1002, 1007 or 1009', function (Closure $send, int $code) {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket');
        $send($conn);
        expect(ws_read($conn))->toBe([8, pack('n', $code)]);
        expect(ws_read($conn))->toBeNull();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
})->with([
    'unmasked'                  => [fn ($conn) => ws_raw($conn, 0x81, 'hi', null), 1002],
    'a reserved bit'            => [fn ($conn) => ws_raw($conn, 0xC1, 'hi'), 1002],
    'a reserved opcode'         => [fn ($conn) => ws_raw($conn, 0x83, 'hi'), 1002],
    'a continuation of nothing' => [fn ($conn) => ws_send($conn, 0, 'hi'), 1002],
    'a new message mid-message' => [function ($conn) {
        ws_send($conn, 1, 'a', false);
        ws_send($conn, 1, 'b');
    }, 1002],
    'a fragmented ping'         => [fn ($conn) => ws_send($conn, 9, 'hi', false), 1002],
    'text that is not UTF-8'    => [fn ($conn) => ws_send($conn, 1, "\xC3\x28"), 1007],
    'too large'                 => [fn ($conn) => fwrite($conn, "\x81\xFF" . pack('J', (1 << 20) + 1) . "\x01\x02\x03\x04"), 1009],
]);

test('WebSocket: a request that is no handshake is refused with 426 or 400', function () {
    [$process, $addr] = swerve_start(workers: 1);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /websocket HTTP/1.1\r\nHost: test\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['headers']['upgrade'] ?? null])->toBe([426, 'websocket']);

        $conn = native_connect($addr);
        fwrite($conn, "GET /websocket HTTP/1.1\r\nHost: test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 8\r\n\r\n");
        $response = native_read_response($conn);
        expect([$response['status'], $response['headers']['sec-websocket-version'] ?? null])->toBe([400, '13']);
    } finally {
        native_stop($process);
    }
});

test('WebSocket: a drain (shutdown) closes open connections with 1001', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    $conn = ws_connect($addr, '/websocket');
    ws_send($conn, 1, 'hi');
    expect(ws_read($conn))->toBe([1, 'hi']);
    swerve_signal($process, SIGTERM);
    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
    swerve_wait($process, 5);
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});
