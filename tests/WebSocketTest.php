<?php

/*
 * Swerve\Http\WebSocket, served by the fixture's /websocket: an echo, as a browser would use it.
 */

beforeEach(fn () => test()->markTestSkipped('phase B: WebSocket on the 101 Duplex'));

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
    // The client's half of the closing handshake, and its close when swerve closes, as a browser's;
    // a client that stays silent would hold the drain for the closing linger (2 s)
    ws_send($conn, 8, pack('n', 1001));
    fread($conn, 1);
    fclose($conn);
    $t = microtime(true);
    swerve_wait($process, 5);
    // Nothing else holds the drain: not the shared ping loop, which is no request's
    expect(microtime(true) - $t)->toBeLessThan(2.0, file_get_contents($log));
    expect(log_count($log, '/(ERROR|CRITICAL|failed|deadline)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: a callback that only sends (a subscription forwarded) gets every publish, and ends when its client leaves', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $clients = [];
        for ($i = 0; $i < 8; ++$i) {
            $clients[] = ws_connect($addr, '/websocket-news');
        }
        usleep(200_000); // every callback subscribed
        expect(probe($addr, '/publish?topic=news&m=first'))->toBe('published');
        expect(probe($addr, '/publish?topic=news&m=second'))->toBe('published');
        foreach ($clients as $conn) {
            expect([ws_read($conn), ws_read($conn)])->toBe([[1, 'first'], [1, 'second']]);
        }
        $live = static function () use ($addr): int {
            $seen = [];
            for ($i = 0; $i < 40 && count($seen) < 2; ++$i) {
                [$pid, $n]  = json_decode((string) probe($addr, '/news-live'), true);
                $seen[$pid] = $n;
            }

            return array_sum($seen);
        };
        expect($live())->toBe(8);

        // Half leave without a word, half say goodbye: every callback ends either way
        foreach ($clients as $i => $conn) {
            $i % 2 ? ws_send($conn, 8, pack('n', 1000)) : fclose($conn);
        }
        $deadline = microtime(true) + 3;
        while ($live() > 0 && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect($live())->toBe(0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('WebSocket: a client that resets its connection (no close) ends the callback, without an error in the log', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-news');
        usleep(100_000);
        $socket = socket_import_stream($conn);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        socket_close($socket); // a reset, as from a killed browser tab
        $deadline = microtime(true) + 3;
        while (json_decode((string) probe($addr, '/news-live'), true)[1] > 0 && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect(json_decode((string) probe($addr, '/news-live'), true)[1])->toBe(0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(!function_exists('socket_create'), 'the test uses ext-sockets');

test('WebSocket: quiet connections are pinged every PING_INTERVAL seconds, by one coroutine for all', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $a = ws_connect($addr, '/websocket');
        $b = ws_connect($addr, '/websocket-news');
        foreach ([$a, $b] as $conn) {
            stream_set_timeout($conn, (int) Swerve\Http\WebSocket::PING_INTERVAL + 3);
            expect(ws_read($conn))->toBe([9, '']);
            ws_send($conn, 10, '');
        }
        ws_send($a, 1, 'still here');
        expect(ws_read($a))->toBe([1, 'still here']);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: a subscription loop in a PSR-15 handler forwards messages and closes the socket on an end message', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $clients = [];
        for ($i = 0; $i < 4; ++$i) {
            $clients[] = ws_connect($addr, '/websocket-feed');
        }
        usleep(200_000); // every callback subscribed
        expect(probe($addr, '/publish?topic=feed&m=one'))->toBe('published');
        expect(probe($addr, '/publish-json?topic=feed&m=two'))->toBe('published');
        foreach ($clients as $conn) {
            expect(ws_read($conn))->toBe([1, 'one']);
            expect(json_decode(ws_read($conn)[1], true))->toBe(['m' => 'two', 'n' => 1, 'list' => [1, 2]]);
        }
        expect(probe($addr, '/publish-end?topic=feed'))->toBe('published');
        foreach ($clients as $conn) {
            expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
            ws_send($conn, 8, pack('n', 1000));
            expect(fread($conn, 1))->toBe('');
            fclose($conn);
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING)/'))->toBe(0, file_get_contents($log));
});

/** What the $onClose of /websocket-events saw in the worker, once there are $count of them (and a moment for more). */
function ws_closes(string $addr, int $count): array
{
    $deadline = microtime(true) + 3;
    do {
        $closes = json_decode((string) probe($addr, '/ws-closes'), true);
        usleep(50_000);
    } while (count($closes) < $count && microtime(true) < $deadline);
    usleep(100_000); // a second trigger would show

    return json_decode((string) probe($addr, '/ws-closes'), true);
}

test('WebSocket: events in, sequential code out: a message from one client reaches the other, and a published end returns the callback', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $a = ws_connect($addr, '/websocket-chat');
        $b = ws_connect($addr, '/websocket-chat');
        usleep(200_000); // both callbacks subscribed
        ws_send($a, 1, 'hello from a');
        expect(ws_read($b))->toBe([1, 'hello from a']);
        expect(ws_read($a))->toBe([1, 'hello from a']);
        ws_send($b, 1, 'and b');
        expect(ws_read($a))->toBe([1, 'and b']);
        expect(ws_read($b))->toBe([1, 'and b']);
        expect(probe($addr, '/publish?topic=chat&m=end'))->toBe('published');
        foreach ([$a, $b] as $conn) {
            expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
            ws_send($conn, 8, pack('n', 1000));
            expect(ws_read($conn))->toBeNull();
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: $onMessage gets the data and whether it is binary', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'hi');
        expect(ws_read($conn))->toBe([1, 't:' . bin2hex('hi')]);
        ws_send($conn, 2, "\x00\xFF");
        expect(ws_read($conn))->toBe([1, 'b:00ff']);
        ws_send($conn, 1, 'frag', false);
        ws_send($conn, 0, 'mented');
        expect(ws_read($conn))->toBe([1, 't:' . bin2hex('fragmented')]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: $onClose fires once when the client says goodbye, with its code and reason, or 1005 without one', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 8, pack('n', 1001) . 'leaving');
        expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        expect(ws_closes($addr, 1))->toBe([[1001, 'leaving']]);

        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 8, '');
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
        expect(ws_closes($addr, 2))->toBe([[1001, 'leaving'], [1005, '']]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: $onClose fires once when the server calls end(), with the code and reason it gave', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'end');
        expect(ws_read($conn))->toBe([8, pack('n', 4000) . 'server done']);
        ws_send($conn, 8, pack('n', 4000));
        expect(ws_read($conn))->toBeNull();
        expect(ws_closes($addr, 1))->toBe([[4000, 'server done']]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: $onClose fires once, with 1006, when the connection is reset', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        usleep(100_000);
        $socket = socket_import_stream($conn);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        socket_close($socket);
        expect(ws_closes($addr, 1))->toBe([[1006, '']]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|Unhandled)/i'))->toBe(0, file_get_contents($log));
})->skip(!function_exists('socket_create'), 'the test uses ext-sockets');

test('WebSocket: a throwing $onMessage listener closes with 1011, is logged, and $onClose still fires', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-events');
        ws_send($conn, 1, 'throw');
        expect(ws_read($conn))->toBe([8, pack('n', 1011)]);
        expect(ws_read($conn))->toBeNull();
        log_wait($log, '/the WebSocket listener failed/');
        expect(ws_closes($addr, 1))->toBe([[1011, '']]);
    } finally {
        native_stop($process);
    }
});

test('WebSocket: receive() throws a LogicException while $onMessage has listeners', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-mixed');
        expect(ws_read($conn))->toBe([1, 'LogicException']);
        expect(ws_read($conn))->toBe([8, pack('n', 1000)]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});

test('WebSocket: a once() listener gets the first message, and the next ones can be received', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-once');
        ws_send($conn, 1, 'one');
        ws_send($conn, 1, 'two');
        ws_send($conn, 1, 'three');
        expect([ws_read($conn), ws_read($conn), ws_read($conn)])->toBe([[1, 'once:one'], [1, 'pull:two'], [1, 'pull:three']]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|failed)/i'))->toBe(0, file_get_contents($log));
});
