<?php

/*
 * Swerve::container()'s limit across workers: tests/Fixtures/container.php, with a service of
 * limit 1 and two workers.
 */

test('a limit holds across the workers: requests that need the one slot take turns, whichever worker serves them', function () {
    [$process, $addr, $log] = swerve_start(workers: 2, fixture: 'container.php');
    try {
        $conns = [];
        for ($i = 0; $i < 6; ++$i) {
            $conns[] = native_connect($addr);
            fwrite(end($conns), "GET /hold?ms=150 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        }
        $held = $pids = [];
        foreach ($conns as $conn) {
            $response = native_read_response($conn);
            expect($response['status'])->toBe(200);
            preg_match('/^(\d+) ([\d.]+)\n([\d.]+)$/', $response['body'], $m);
            $pids[$m[1]] = true;
            $held[]      = [(float) $m[2], (float) $m[3]];
        }
        sort($held);
        for ($i = 1; $i < count($held); ++$i) {
            expect($held[$i][0])->toBeGreaterThanOrEqual($held[$i - 1][1]); // never two at once
            expect($held[$i][0] - $held[$i - 1][1])->toBeLessThan(0.5);     // the next is woken by the release, in whichever worker
        }
        expect(count($pids))->toBe(2, 'both workers served: otherwise this shows nothing across workers');
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING|Warning:)/'))->toBe(0, (string) file_get_contents($log));
});

test('a worker that dies holding a slot gives it back', function () {
    [$process, $addr, $log] = swerve_start(workers: 2, fixture: 'container.php');
    try {
        $holder = native_connect($addr);
        fwrite($holder, "GET /hold?ms=60000 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        $line = '';
        while (!preg_match('/\r\n\r\n[0-9a-f]+\r\n(\d+) /', $line, $m)) {
            $more = fread($holder, 1024);
            expect($more)->not->toBe('', $line);
            $line .= $more;
        }
        posix_kill((int) $m[1], SIGKILL);
        $start = microtime(true);
        expect(probe($addr, '/take', 5.0))->toMatch('/^\d+ /');
        expect(microtime(true) - $start)->toBeLessThan(3.0);
    } finally {
        native_stop($process);
    }
});
