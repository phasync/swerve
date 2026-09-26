<?php

/*
 * The examples in examples/, run as the documentation says, with two workers each: what one
 * client sends reaches the others, whichever workers they landed on.
 */

function example_start(string $name): array
{
    $dir = __DIR__ . "/../examples/$name";
    [$process, $addr, $log] = swerve_start(["--public=$dir/public"], 2, fixture: "$dir/swerve.php", wait: false);
    $deadline = microtime(true) + 10;
    while (null === probe($addr, '/')) {
        expect(microtime(true))->toBeLessThan($deadline, file_get_contents($log));
        usleep(20_000);
    }

    return [$process, $addr, $log];
}

test('the SSE chat: messages posted reach every listener, and bad ones are refused', function () {
    [$process, $addr, $log] = example_start('sse-chat');
    try {
        expect(probe($addr, '/'))->toContain('EventSource');
        $listeners = [];
        for ($i = 0; $i < 6; ++$i) {
            $conn = native_connect($addr);
            fwrite($conn, "GET /events HTTP/1.1\r\nHost: t\r\n\r\n");
            expect(native_read_head($conn)['headers']['content-type'])->toBe('text/event-stream');
            expect(native_read_chunk($conn))->toBe("retry: 1000\n\n");
            $listeners[] = $conn;
        }
        $post = static function (string $json) use ($addr): int {
            $conn = native_connect($addr);
            fwrite($conn, "POST /messages HTTP/1.1\r\nHost: t\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");

            return native_read_response($conn)['status'];
        };
        expect($post('{"name":"ann","text":"hi"}'))->toBe(204);
        expect($post('{"name":"","text":"hi"}'))->toBe(422);
        foreach ($listeners as $conn) {
            $event = json_decode(substr(native_read_chunk($conn), 6), true);
            expect([$event['name'], $event['text']])->toBe(['ann', 'hi']);
        }
        // A drain ends the subscriptions, so the event streams end at once, not at the deadline
        $start = microtime(true);
        swerve_signal($process, SIGINT);
        foreach ($listeners as $conn) {
            expect(native_read_chunk($conn))->toBe('');
            fclose($conn);
        }
        expect(microtime(true) - $start)->toBeLessThan(1.0);
        swerve_wait($process, 5);
        $stopped = true;
    } finally {
        if (!isset($stopped)) {
            native_stop($process);
        }
    }
    expect(log_count($log, '/(error|critical)/'))->toBe(0, file_get_contents($log));
});

test('the WebSocket chat: messages sent reach every connection; a ping gets its pong; a close is answered', function () {
    [$process, $addr, $log] = example_start('websocket-chat');
    try {
        expect(probe($addr, '/'))->toContain('new WebSocket');
        $clients = [];
        for ($i = 0; $i < 6; ++$i) {
            $clients[] = ws_connect($addr, '/chat');
        }
        usleep(100_000); // every connection subscribed
        ws_send($clients[0], 1, '{"name":"bob","text":"hello"}');
        ws_send($clients[1], 1, '{"name":"","text":"refused"}');
        foreach ($clients as $conn) {
            [$opcode, $payload] = ws_read($conn);
            $message            = json_decode($payload, true);
            expect([$opcode, $message['name'], $message['text']])->toBe([1, 'bob', 'hello']);
        }
        ws_send($clients[2], 9, 'are you there');
        expect(ws_read($clients[2]))->toBe([10, 'are you there']);
        ws_send($clients[3], 8, pack('n', 1000));
        expect(ws_read($clients[3]))->toBe([8, pack('n', 1000)]);
        expect(ws_read($clients[3]))->toBeNull();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(error|critical)/'))->toBe(0, file_get_contents($log));
});
