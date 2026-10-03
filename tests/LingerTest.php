<?php

/*
 * Lingering workers: a worker replaced by a recycle keeps serving its upgraded connections
 * (WebSocket) until the last one is gone, or --linger seconds passed. A shutdown or a reload
 * ends the lingering at once, with --grace. The fixture's /websocket-shutdown tells its
 * worker's pid, echoes, and answers a shutdown with 1001.
 */

/**
 * @return array{0: resource, 1: int} a WebSocket to /websocket-shutdown (or $path), and the pid of the worker it is on
 */
function linger_ws(string $addr, string $path = '/websocket-shutdown'): array
{
    $conn = ws_connect($addr, $path);
    [, $pid] = ws_read($conn);

    return [$conn, (int) $pid];
}

/**
 * Ask /pid until a worker other than $pid answers: $pid was recycled, and its replacement serves.
 */
function linger_until_replaced(string $addr, int $pid, float $timeout = 10): int
{
    for ($until = microtime(true) + $timeout; microtime(true) < $until; usleep(5000)) {
        $answer = probe($addr, '/pid');
        if (null !== $answer && (int) $answer !== $pid) {
            return (int) $answer;
        }
    }
    throw new RuntimeException("Worker $pid was not replaced within $timeout s");
}

function linger_alive(int $pid): bool
{
    return posix_kill($pid, 0);
}

function linger_close($conn): void
{
    ws_send($conn, 8, pack('n', 1000));
    expect(ws_read($conn)[0] ?? null)->toBe(8);
    fclose($conn);
}

test('a recycled worker keeps its WebSocket, past the grace period, and exits once the last one closes', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5', '--grace=1'], workers: 1);
    try {
        [$conn, $old] = linger_ws($addr);
        $new          = linger_until_replaced($addr, $old);
        expect($new)->not->toBe($old);
        log_wait($log, "/took over, draining $old \\(recycle\\)/");

        usleep(2_500_000); // longer than the grace: a drain would have dropped it
        ws_send($conn, 1, 'still here');
        expect(ws_read($conn))->toBe([1, 'still here']);
        expect(linger_alive($old))->toBeTrue();
        expect(log_count($log, "/Worker $old \\(slot 0\\) exited/"))->toBe(0);

        linger_close($conn);
        log_wait($log, "/Worker $old \\(slot 0\\) exited after draining/", 3);
        expect(log_count($log, '/Killing worker/'))->toBe(0);
    } finally {
        native_stop($process);
    }
});

test('a lingering worker accepts no new connections and finishes its plain requests', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5'], workers: 1);
    try {
        [$conn, $old] = linger_ws($addr);
        linger_until_replaced($addr, $old);
        for ($i = 0; $i < 4; ++$i) {
            expect((int) probe($addr, '/pid'))->not->toBe($old);
        }
        // No other WebSocket on it either: the connections of the new worker
        [$other, $pid] = linger_ws($addr);
        expect($pid)->not->toBe($old);
        linger_close($other);
        linger_close($conn);
    } finally {
        native_stop($process);
    }
});

test('--linger ends the lingering: the callback of Swerve::onShutdown() runs then, not when the drain begins, and the WebSocket gets 1001', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5', '--linger=1.5'], workers: 1);
    try {
        [$conn, $old] = linger_ws($addr);
        linger_until_replaced($addr, $old);
        $recycled = microtime(true);
        expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        expect(microtime(true) - $recycled)->toBeGreaterThan(1.0);
        expect(ws_read($conn))->toBeNull();
        log_wait($log, "/Worker $old \\(slot 0\\) exited after draining/", 3);
        $text = file_get_contents($log);
        preg_match("/took over, draining $old \\(recycle\\)/", $text, $over, PREG_OFFSET_CAPTURE);
        preg_match('/onShutdown callback ran/', $text, $ran, PREG_OFFSET_CAPTURE);
        expect($over[0][1] ?? PHP_INT_MAX)->toBeLessThan($ran[0][1] ?? 0);
    } finally {
        native_stop($process);
    }
});

test('--linger=0 drains a recycled worker as before: its WebSocket is closed at once', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5', '--linger=0'], workers: 1);
    try {
        [$conn, $old] = linger_ws($addr);
        linger_until_replaced($addr, $old);
        $recycled = microtime(true);
        expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        expect(microtime(true) - $recycled)->toBeLessThan(1.0);
    } finally {
        native_stop($process);
    }
});

test('a shutdown ends the lingering with the grace period: the WebSocket is told, and swerve stops long before --linger', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5', '--linger=600'], workers: 1);
    [$conn, $old]           = linger_ws($addr);
    linger_until_replaced($addr, $old);
    $start = microtime(true);
    swerve_signal($process, SIGTERM);
    expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
    [$code, $took] = swerve_wait($process, 5);
    expect($code)->toBe(0);
    expect(microtime(true) - $start)->toBeLessThan(4.0);
    expect(linger_alive($old))->toBeFalse();
    expect(log_count($log, '/onShutdown callback ran/'))->toBe(1);
});

test('a reload ends the lingering too', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=5', '--linger=600'], workers: 1);
    try {
        [$conn, $old] = linger_ws($addr);
        linger_until_replaced($addr, $old);
        swerve_signal($process, SIGHUP);
        expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        log_wait($log, "/Worker $old \\(slot 0\\) exited after draining/", 4);
    } finally {
        native_stop($process);
    }
});

test('a lingering worker keeps its subscriptions: what is published still reaches its WebSocket', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=8'], workers: 1);
    try {
        $conn = ws_connect($addr, '/websocket-news');
        // Subscribed once the handler ran: /news-live counts it
        for ($until = microtime(true) + 5; 1 !== (json_decode(probe($addr, '/news-live') ?? '[]')[1] ?? null) && microtime(true) < $until; usleep(10000)) {
        }
        $old = (int) json_decode(probe($addr, '/news-live'))[0];
        linger_until_replaced($addr, $old);
        expect(probe($addr, '/publish?topic=news&m=hello'))->toBe('published');
        expect(ws_read($conn))->toBe([1, 'hello']);
        expect(linger_alive($old))->toBeTrue();
    } finally {
        native_stop($process);
    }
});

test('a slot keeps at most 3 lingering workers: the next recycle waits, is logged once, and goes on when one exits', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=8'], workers: 1);
    try {
        $connections = [];
        $pids        = [];
        for ($i = 0; $i < 3; ++$i) {
            [$connections[$i], $pids[$i]] = linger_ws($addr);
            linger_until_replaced($addr, $pids[$i]);
        }
        expect(count(array_unique($pids)))->toBe(3);
        // The fourth worker, serving, wants a recycle too
        [$connections[3], $pids[3]] = linger_ws($addr);
        for ($until = microtime(true) + 5; 0 === log_count($log, '/Slot 0: recycle waits/') && microtime(true) < $until; usleep(10000)) {
            expect(probe($addr, '/pid'))->toBe((string) $pids[3]);
        }
        log_wait($log, '/Slot 0: recycle waits for a lingering worker to exit/');
        for ($i = 0; $i < 10; ++$i) {
            expect(probe($addr, '/pid'))->toBe((string) $pids[3]); // it serves on
        }
        expect(log_count($log, '/Slot 0: recycle waits for a lingering worker to exit/'))->toBe(1);
        expect(log_count($log, '/waiting for a draining worker to exit before starting another/'))->toBe(0);

        linger_close($connections[0]); // the oldest leaves: the recycle goes on
        $fifth = linger_until_replaced($addr, $pids[3]);
        expect($fifth)->not->toBeIn($pids);
        expect(linger_alive($pids[1]) && linger_alive($pids[2]) && linger_alive($pids[3]))->toBeTrue();
    } finally {
        native_stop($process);
    }
});

test('a reload while a slot has 3 lingering workers does not wait for them', function () {
    [$process, $addr, $log] = swerve_start(['--max-requests=8', '--linger=600'], workers: 1);
    try {
        $connections = [];
        $pids        = [];
        for ($i = 0; $i < 3; ++$i) {
            [$connections[$i], $pids[$i]] = linger_ws($addr);
            linger_until_replaced($addr, $pids[$i]);
        }
        swerve_signal($process, SIGHUP);
        foreach ($connections as $conn) {
            expect(ws_read($conn))->toBe([8, pack('n', 1001)]);
        }
        log_wait($log, '/Reload complete/', 6);
        expect(probe($addr, '/pid'))->not->toBeNull();
    } finally {
        native_stop($process);
    }
});
