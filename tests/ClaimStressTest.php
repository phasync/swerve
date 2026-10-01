<?php

/*
 * Swerve::claim() under attack: handles without a master, mutual exclusion under contention,
 * and what happens to claims as workers drain, die or stop. The basics are in ClaimTest.php.
 */

use Swerve\Claim;
use Swerve\Swerve;

/** The lock file of a name, in this process's claims directory. */
function claim_path(string $name): string
{
    return Claim::directory() . '/' . hash('sha256', $name);
}

/** The pids in the claims of a directory (not counting the files that hold a process's pid). */
function claim_holders(string $dir): array
{
    return array_map('file_get_contents', glob("$dir/[0-9a-f]*"));
}

/** How many claims this process holds, once the garbage of earlier tests (handles in cycles) is gone. */
function claims_here(): int
{
    gc_collect_cycles();

    return count(glob(Claim::directory() . '/[0-9a-f]*'));
}

/**
 * Send every path on a connection of its own at once, then read them all.
 *
 * @param string[] $paths
 *
 * @return array<int, array{status: int, json: mixed}|null> by index; null for a connection that gave no answer
 */
function claim_burst(string $addr, array $paths, float $timeout = 30): array
{
    $conns = $buffers = [];
    foreach ($paths as $i => $path) {
        $conns[$i]   = $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
        $buffers[$i] = '';
        fwrite($conn, "GET $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        stream_set_blocking($conn, false);
    }
    $open     = $conns;
    $deadline = microtime(true) + $timeout;
    while ($open && microtime(true) < $deadline) {
        $read = $open;
        $none = null;
        if (!stream_select($read, $none, $none, 1)) {
            continue;
        }
        foreach ($read as $i => $conn) {
            $chunk = fread($conn, 65536);
            if (false === $chunk || ('' === $chunk && feof($conn))) {
                unset($open[$i]);
            } else {
                $buffers[$i] .= $chunk;
            }
        }
    }
    $results = [];
    foreach ($conns as $i => $conn) {
        fclose($conn);
        $parts       = explode("\r\n\r\n", $buffers[$i], 2);
        $results[$i] = 2 === count($parts) && preg_match('#^HTTP/1\.1 (\d+)#', $parts[0], $m)
            ? ['status' => (int) $m[1], 'json' => json_decode($parts[1], true)]
            : null;
    }

    return $results;
}

/** A POST whose body is $body, answered as JSON; null when it failed. */
function claim_post(string $addr, string $path, string $body): mixed
{
    $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    stream_set_timeout($conn, 10);
    fwrite($conn, "POST $path HTTP/1.1\r\nHost: test\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    $response = native_read_response($conn);
    fclose($conn);

    return null !== $response && 200 === $response['status'] ? json_decode($response['body'], true) : null;
}

/** A GET answered as JSON, retried on fresh connections until something answers (a worker may just have died). */
function claim_retry(string $addr, string $path, float $timeout = 10): array
{
    $deadline = microtime(true) + $timeout;
    do {
        $answer = probe($addr, $path);
        if (null !== $answer) {
            return json_decode($answer, true);
        }
        usleep(30_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("No answer to $path within $timeout s");
}

/** Wait until the log has at least $n matches. */
function log_wait_count(string $log, string $regex, int $n, float $timeout = 10): void
{
    $deadline = microtime(true) + $timeout;
    while (log_count($log, $regex) < $n) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Fewer than $n of $regex in the log within $timeout s:\n" . file_get_contents($log));
        }
        usleep(50_000);
    }
}

/*
 * 1. Handles without a master.
 */

test('without a master, hostile names are claimable through the handle', function () {
    $before = claims_here();
    $claims = [];
    foreach (['0', "\0", "a\0b", '日本語', str_repeat('x', 65536)] as $name) {
        $claim = Swerve::claim($name);
        expect($claim->available())->toBeTrue();
        expect($claim->acquire())->toBe($claim);
        expect(Swerve::claim($name)->available())->toBeFalse();
        expect(Swerve::claim($name)->acquire())->toBeNull();
        $claims[] = $claim;
    }
    expect(claims_here())->toBe($before + 5);
    foreach ($claims as $claim) {
        $claim->release();
    }
    expect(claims_here())->toBe($before);
});

test('without a master, 2000 handles acquired and released leave nothing behind', function () {
    $before = claims_here();
    for ($i = 0; $i < 2000; ++$i) {
        $claim = Swerve::claim("bulk-$i");
        $claim->acquire();
        if ($i % 2) {
            $claim->release();
        } // the rest go by the destructor, when $claim is replaced
    }
    unset($claim);
    expect(claims_here())->toBe($before);
});

test('without a master, a handle released twice or never acquired never frees another holder', function () {
    $a = Swerve::claim('twice-released');
    expect($a->acquire())->toBe($a);
    $a->release();
    $a->release(); // twice

    $b = Swerve::claim('twice-released');
    expect($b->acquire())->toBe($b);
    $a->release(); // a third time, now that another holds the name
    expect($a->held())->toBeFalse();
    expect($b->held())->toBeTrue();
    expect(Swerve::claim('twice-released')->available())->toBeFalse();

    $never = Swerve::claim('twice-released'); // never acquired
    $never->release();
    expect($never->acquire())->toBeNull();    // refused, and still not holding
    $never->release();
    unset($never);
    expect($b->held())->toBeTrue();

    $a->release();
    unset($a);
    expect($b->held())->toBeTrue();
    $b->release();
    expect(Swerve::claim('twice-released')->available())->toBeTrue();
});

test('without a master, a released handle can acquire again, and a refused one acquires once the name frees', function () {
    $a = Swerve::claim('again');
    $b = Swerve::claim('again');
    expect($a->acquire())->toBe($a);
    expect($b->acquire())->toBeNull();
    expect($b->held())->toBeFalse();
    $a->release();
    expect($b->acquire())->toBe($b);
    expect($a->acquire())->toBeNull();
    $b->release();
    expect($a->acquire())->toBe($a); // the same handle again, after its release
    expect($a->held())->toBeTrue();
    unset($b); // the refused, released handle: its destructor frees nothing
    expect($a->held())->toBeTrue();
    $a->release();
    expect(Swerve::claim('again')->available())->toBeTrue();
});

test('without a master, destructors release: scope end, unset, reassignment, exception unwinding and the garbage collector', function () {
    $before = claims_here();

    $claim = Swerve::claim('d-unset');
    $claim->acquire();
    unset($claim);
    expect(Swerve::claim('d-unset')->available())->toBeTrue();

    $claim = Swerve::claim('d-reassign');
    $claim->acquire();
    $claim = Swerve::claim('d-reassign-2'); // the first is gone
    expect(Swerve::claim('d-reassign')->available())->toBeTrue();
    $claim = null;

    $thrown = function () {
        $claim = Swerve::claim('d-throw');
        $claim->acquire();
        throw new RuntimeException('unwinding');
    };
    expect($thrown)->toThrow(RuntimeException::class);
    expect(Swerve::claim('d-throw')->available())->toBeTrue();

    // A handle in a reference cycle goes when the collector finds it
    $node       = new stdClass();
    $node->self = $node;
    $node->claim = Swerve::claim('d-cycle');
    $node->claim->acquire();
    unset($node);
    expect(Swerve::claim('d-cycle')->available())->toBeFalse(); // not yet collected
    gc_collect_cycles();
    expect(Swerve::claim('d-cycle')->available())->toBeTrue();

    // A handle kept by a closure that goes
    $keeper = (function () {
        $claim = Swerve::claim('d-closure');
        $claim->acquire();

        return fn () => $claim->held();
    })();
    expect($keeper())->toBeTrue();
    unset($keeper);
    expect(Swerve::claim('d-closure')->available())->toBeTrue();

    expect(claims_here())->toBe($before);
});

test('without a master, a coroutine ending with a claim, by return, exception or cancellation, releases it', function () {
    $before = claims_here();
    phasync::run(function () {
        $returned = phasync::go(function () {
            $claim = Swerve::claim('co-return');
            $claim->acquire();
            phasync::sleep(0.01);
        });
        $failed = phasync::go(function () {
            $claim = Swerve::claim('co-throw');
            $claim->acquire();
            phasync::sleep(0.01);
            throw new RuntimeException('coroutine failed');
        });
        $cancelled = phasync::go(function () {
            $claim = Swerve::claim('co-cancel');
            $claim->acquire();
            phasync::sleep(30);
        });
        phasync::sleep(0.05);
        expect(Swerve::claim('co-cancel')->available())->toBeFalse(); // held in the sleeping coroutine
        phasync::cancel($cancelled);
        phasync::sleep(0.01); // the coroutine unwinds as it is resumed
        expect(Swerve::claim('co-cancel')->available())->toBeTrue();
        phasync::await($returned);
        try {
            phasync::await($failed);
        } catch (RuntimeException) {
        }
        foreach (['co-return', 'co-throw', 'co-cancel'] as $name) {
            expect(Swerve::claim($name)->available())->toBeTrue();
        }
    });
    expect(claims_here())->toBe($before);
});

test('without a master, acquire(timeout) with coroutines: one waiter gets a freed name, the other times out', function () {
    $held = Swerve::claim('contended');
    $held->acquire();
    $results = phasync::run(function () use ($held) {
        $start   = microtime(true);
        $waiters = [];
        foreach ([1, 2] as $n) {
            $waiters[$n] = phasync::go(static function () use ($n, $start) {
                $claim = Swerve::claim('contended');
                $got   = $claim->acquire(0.5);
                $at    = microtime(true) - $start;
                if ($got) {
                    phasync::sleep(0.7); // keeps it past the other's deadline
                }

                return [null !== $got, $at];
            });
        }
        phasync::sleep(0.1);
        $held->release();

        return array_map(phasync::await(...), $waiters);
    });
    $won = array_values(array_filter($results, static fn ($r) => $r[0]));
    $lost = array_values(array_filter($results, static fn ($r) => !$r[0]));
    expect([count($won), count($lost)])->toBe([1, 1]);
    expect($won[0][1])->toBeGreaterThanOrEqual(0.09)->toBeLessThan(0.3); // within a poll of the release
    expect($lost[0][1])->toBeGreaterThanOrEqual(0.5)->toBeLessThan(0.7);
    expect(Swerve::claim('contended')->available())->toBeTrue(); // the winner's handle went with its coroutine
});

test('without a master, acquire(0) on a taken name answers at once, and a negative timeout also', function () {
    $held = Swerve::claim('quick');
    $held->acquire();
    $start = microtime(true);
    expect(Swerve::claim('quick')->acquire())->toBeNull();
    expect(Swerve::claim('quick')->acquire(-1.0))->toBeNull();
    expect(Swerve::claim('quick')->acquire(0.0))->toBeNull();
    expect(microtime(true) - $start)->toBeLessThan(0.05);
    $held->release();
    expect(Swerve::claim('quick')->acquire(-1.0))->toBeInstanceOf(Claim::class); // a free name needs no time
});

test('without a master, an acquire cancelled while it waits leaves nothing held', function () {
    $held = Swerve::claim('waited-for');
    $held->acquire();
    $before = claims_here();
    phasync::run(function () {
        $waiter = phasync::go(function () {
            Swerve::claim('waited-for')->acquire(30);
        });
        phasync::sleep(0.05);
        phasync::cancel($waiter);
    });
    expect(claims_here())->toBe($before);
    $held->release();
    expect(Swerve::claim('waited-for')->available())->toBeTrue();
});

/*
 * 2. Mutual exclusion under contention: 4 workers, many requests at once.
 */

function claim_contention(bool $drop): void
{
    $dir = temp_path(true);
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    try {
        worker_pids($addr, 4, 10); // all four serve
        $total = 80;
        $paths = [];
        for ($seq = 1; $seq <= $total; ++$seq) {
            $paths[] = "/crit?n=crit&seq=$seq&ms=5&timeout=8" . ($drop ? '&drop=1' : '');
        }
        $results = claim_burst($addr, $paths, 40);

        $done = $timedOut = [];
        $pids = [];
        foreach ($results as $i => $result) {
            expect($result)->not->toBeNull("request $i got no answer");
            expect($result['status'])->toBe(200);
            [$pid, $outcome, $seq] = $result['json'];
            expect($outcome)->toBeIn(['done', 'timeout']);
            if ('done' === $outcome) {
                $done[$seq] = $pid;
            } else {
                $timedOut[$seq] = $pid;
            }
        }
        expect(count($done) + count($timedOut))->toBe($total);
        expect(count($done))->toBeGreaterThanOrEqual(60);
        expect(count(array_unique($done)))->toBeGreaterThanOrEqual(2); // the exclusion held across processes

        $lines = file($dir . '/crit.log', FILE_IGNORE_NEW_LINES);
        expect(count($lines))->toBe(2 * count($done));
        $inside = null;
        $entered = [];
        foreach ($lines as $n => $line) {
            $parts = explode(' ', $line);
            if ('enter' === $parts[0]) {
                expect($inside)->toBeNull("line $n: entered while $inside was inside");
                $inside    = $parts[1];
                $entered[] = (int) $parts[1];
                expect($done[(int) $parts[1]] ?? null)->toBe((int) $parts[2]); // by the worker that answered
            } else {
                expect($parts[0])->toBe('leave');
                expect($inside)->toBe($parts[1], "line $n: left what was not entered");
                $inside = null;
            }
        }
        expect($inside)->toBeNull();
        sort($entered);
        $keys = array_keys($done);
        sort($keys);
        expect($entered)->toBe($keys);
        expect(claim_retry($addr, '/available?n=crit')[1])->toBeTrue(); // every handle let go of it
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
}

test('4 workers, 80 concurrent requests: the critical sections never overlap (explicit release)', function () {
    claim_contention(false);
});

test('4 workers, 80 concurrent requests: the critical sections never overlap (handle dropped)', function () {
    claim_contention(true);
});

test('contention with timeouts that expire: every request completes or reports a timeout, never overlaps', function () {
    $dir = temp_path(true);
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    try {
        worker_pids($addr, 4, 10);
        $paths = [];
        for ($seq = 1; $seq <= 40; ++$seq) {
            $paths[] = "/crit?n=short&seq=$seq&ms=60&timeout=0.5"; // 40 x 60 ms cannot all fit in 0.5 s
        }
        $results = claim_burst($addr, $paths, 30);
        $outcomes = array_count_values(array_map(static fn ($r) => $r['json'][1] ?? 'none', $results));
        expect(array_keys($outcomes))->toEqualCanonicalizing(['done', 'timeout']);
        expect($outcomes['done'])->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(40);
        expect($outcomes['timeout'])->toBeGreaterThanOrEqual(1);
        $lines = file($dir . '/crit.log', FILE_IGNORE_NEW_LINES);
        expect(count($lines))->toBe(2 * $outcomes['done']);
        foreach ($lines as $n => $line) {
            expect(str_starts_with($line, $n % 2 ? 'leave' : 'enter'))->toBeTrue("line $n: $line");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('independent names are held at once by different workers: no needless exclusion', function () {
    $dir = temp_path(true);
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    try {
        worker_pids($addr, 4, 10);
        $paths = [];
        for ($seq = 1; $seq <= 40; ++$seq) {
            $paths[] = "/crit?n=own$seq&seq=$seq&ms=100&timeout=0";
        }
        $start   = microtime(true);
        $results = claim_burst($addr, $paths, 30);
        expect(microtime(true) - $start)->toBeLessThan(3.0); // not one after the other: 40 x 100 ms
        foreach ($results as $result) {
            expect($result['json'][1])->toBe('done');
        }
        // Overlap is what is expected here: more than one section was open at a time
        $open = $max = 0;
        foreach (file($dir . '/crit.log', FILE_IGNORE_NEW_LINES) as $line) {
            $open += str_starts_with($line, 'enter') ? 1 : -1;
            $max = max($max, $open);
        }
        expect($max)->toBeGreaterThan(1);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('the unlink on release races with contenders: 400 requests on one name through 4 workers still alternate enter and leave', function () {
    $dir = temp_path(true);
    [$process, $addr, $log] = swerve_start(workers: 4, env: ['SWERVE_TEST_DIR' => $dir]);
    try {
        worker_pids($addr, 4, 10);
        $paths = [];
        for ($seq = 1; $seq <= 400; ++$seq) {
            $paths[] = "/crit?n=hot&seq=$seq&ms=0&timeout=25" . (0 === $seq % 2 ? '&drop=1' : '');
        }
        $results = claim_burst($addr, $paths, 60);
        foreach ($results as $i => $result) {
            expect($result['json'][1] ?? null)->toBe('done', "request $i");
        }
        $lines = file($dir . '/crit.log', FILE_IGNORE_NEW_LINES);
        expect(count($lines))->toBe(800);
        foreach ($lines as $n => $line) {
            expect(str_starts_with($line, $n % 2 ? 'leave' : 'enter'))->toBeTrue("line $n: $line");
            if ($n % 2) {
                expect(explode(' ', $line)[1])->toBe(explode(' ', $lines[$n - 1])[1]); // the section that entered, left
            }
        }
        $claims = cache_call($addr, '/claim-dir')[1];
        expect(glob("$claims/[0-9a-f]*"))->toBe([]); // every entry was unlinked by its holder
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('4 workers racing for one free name at once: exactly one wins', function () {
    [$process, $addr, $log] = swerve_start(workers: 4);
    try {
        worker_pids($addr, 4, 10);
        for ($round = 0; $round < 5; ++$round) {
            $results = claim_burst($addr, array_fill(0, 24, "/claim?n=race$round"), 20);
            $wins    = array_filter($results, static fn ($r) => true === $r['json'][1]);
            expect(count($wins))->toBe(1);
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

/*
 * 3. Failure and lifecycle.
 */

test('a holder killed with SIGKILL frees the name for the other workers (2 and 4 workers)', function () {
    foreach ([2, 4] as $workers) {
        [$process, $addr, $log] = swerve_start(workers: $workers);
        try {
            worker_pids($addr, $workers, 10);
            [$holder, $got] = cache_call($addr, '/claim?n=job');
            expect($got)->toBeTrue();
            expect(cache_call($addr, '/available?n=job')[1])->toBeFalse();
            $dir = cache_call($addr, '/claim-dir')[1];
            expect(claim_holders($dir))->toBe([(string) $holder]);
            posix_kill($holder, SIGKILL);

            $start = microtime(true);
            [$pid, $got] = claim_retry($addr, '/claim?n=job&timeout=8');
            expect($got)->toBeTrue("with $workers workers");
            expect($pid)->not->toBe($holder);
            expect(microtime(true) - $start)->toBeLessThan(4.0);
            expect(claim_holders($dir))->toBe([(string) $pid]); // the dead worker's entry was cleared, not taken over

            // Taken once: a burst now is refused everywhere, the new holder included
            foreach (claim_burst($addr, array_fill(0, 12, '/claim?n=job'), 20) as $result) {
                expect($result['json'][1])->toBeFalse();
            }
        } finally {
            native_stop($process);
        }
        expect(log_count($log, '/CRITICAL/'))->toBe(0, file_get_contents($log));
    }
});

test('a SIGKILL of a worker frees all the names it holds, and only its own (2 and 4 workers)', function () {
    foreach ([2, 4] as $workers) {
        [$process, $addr, $log] = swerve_start(workers: $workers);
        try {
            worker_pids($addr, $workers, 10);
            $by = [];
            for ($i = 0; $i < 20 * $workers; ++$i) {
                [$pid, $got] = cache_call($addr, "/claim?n=multi$i");
                expect($got)->toBeTrue();
                $by[$pid][] = $i;
            }
            expect(count($by))->toBe($workers);
            $victim = array_key_first($by);
            posix_kill($victim, SIGKILL);
            $deadline = microtime(true) + 10;
            do {
                usleep(50_000);
                $free = array_filter($by[$victim], static fn ($i) => claim_retry($addr, "/available?n=multi$i")[1]);
            } while ($free !== $by[$victim] && microtime(true) < $deadline);
            expect($free)->toBe($by[$victim], "with $workers workers");
            unset($by[$victim]);
            foreach ($by as $names) {
                foreach ($names as $i) {
                    expect(claim_retry($addr, "/available?n=multi$i")[1])->toBeFalse(); // the survivors keep their own
                }
            }
        } finally {
            native_stop($process);
        }
        expect(log_count($log, '/CRITICAL/'))->toBe(0, file_get_contents($log));
    }
});

test('SIGTERM of the master while a claim is held and a request is inside its critical section: a clean stop', function () {
    $dir = temp_path(true);
    [$process, $addr, $log] = swerve_start(workers: 2, env: ['SWERVE_TEST_DIR' => $dir]);
    worker_pids($addr, 2, 10);
    for ($i = 0; $i < 8; ++$i) {
        expect(cache_call($addr, "/claim?n=kept$i")[1])->toBeTrue(); // handles kept in both workers
    }
    $inside = native_connect($addr);
    fwrite($inside, "GET /claim-hold?n=inside&ms=800 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    $deadline = microtime(true) + 5;
    while (cache_call($addr, '/available?n=inside')[1]) { // until that request holds it
        expect(microtime(true))->toBeLessThan($deadline);
        usleep(20_000);
    }
    swerve_signal($process, SIGTERM);
    $response = native_read_response($inside);
    expect($response['status'] ?? null)->toBe(200); // the request in flight finishes within the grace
    [$code] = swerve_wait($process, 10);
    expect($code)->toBe(0);
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('handles kept by every worker are destroyed as the workers stop: SIGINT, no errors', function () {
    [$process, $addr, $log] = swerve_start(workers: 4);
    try {
        worker_pids($addr, 4, 10);
        $holders = [];
        for ($i = 0; $i < 60; ++$i) {
            $holders[cache_call($addr, "/claim?n=held$i")[0]] = true;
        }
        expect(count($holders))->toBeGreaterThanOrEqual(3);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING)/i'))->toBe(0, file_get_contents($log));
});

test('SIGHUP reloads x3: the claim is retained while its worker drains, free once the worker exits', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        worker_pids($addr, 2, 10);
        $dir = cache_call($addr, '/claim-dir')[1];
        $holders = [];
        for ($round = 1; $round <= 3; ++$round) {
            // A connection that stays with the holding worker, and a request on it that keeps the worker draining
            $deadline = microtime(true) + 15;
            do {
                $conn = native_connect($addr);
                fwrite($conn, "GET /claim?n=job HTTP/1.1\r\nHost: t\r\n\r\n");
                [$holder, $got] = json_decode(native_read_response($conn)['body'], true);
                if (!$got) {
                    fclose($conn);
                    usleep(50_000);
                }
            } while (!$got && microtime(true) < $deadline);
            expect($got)->toBeTrue("round $round");
            expect($holders)->not->toContain($holder);
            $holders[] = $holder;
            fwrite($conn, "GET /claim-hold?n=pin$round&ms=1500 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
            usleep(100_000);

            swerve_signal($process, SIGHUP);
            log_wait_count($log, '/Reload complete/', $round, 15);
            for ($i = 0; $i < 10; ++$i) { // the new workers cannot take what the draining one still holds
                [$pid, $got] = cache_call($addr, '/claim?n=job');
                expect([$pid !== $holder, $got])->toBe([true, false]);
            }
            expect(cache_call($addr, '/available?n=job')[1])->toBeFalse();
            expect(native_read_response($conn)['status'] ?? null)->toBe(200);
            log_wait_count($log, '/exited after draining/', 2 * $round, 15);
            expect(is_dir($dir))->toBeTrue(); // the workers never remove the master's directory
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a request that fails with a claim held releases it as the exception unwinds', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        for ($i = 0; $i < 6; ++$i) {
            expect(probe($addr, "/claim-throw?n=thrown$i"))->toBeNull(); // 500
            expect(cache_call($addr, "/available?n=thrown$i")[1])->toBeTrue();
            expect(cache_call($addr, "/claim?n=thrown$i")[1])->toBeTrue();
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/CRITICAL/'))->toBe(0, file_get_contents($log));
});

test('a coroutine cancelled while it holds a claim releases it', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        for ($i = 0; $i < 4; ++$i) {
            expect(cache_call($addr, "/claim-cancel?n=held-cancel$i&mode=hold")[1])->toBeTrue();
            expect(cache_call($addr, "/available?n=held-cancel$i")[1])->toBeTrue();
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a held claim whose connection closes mid-request is released when the request ends', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $conn = native_connect($addr);
        fwrite($conn, "GET /claim-hold?n=closing&ms=700 HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
        $deadline = microtime(true) + 5;
        while (cache_call($addr, '/available?n=closing')[1]) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(20_000);
        }
        fclose($conn); // the client is gone while the handler holds the claim
    } finally {
        // The request runs to its end or is cancelled: either way the claim is free within a moment
        $deadline = microtime(true) + 5;
        while (!claim_retry($addr, '/available?n=closing')[1] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect(claim_retry($addr, '/available?n=closing')[1])->toBeTrue();
        expect(claim_retry($addr, '/claim?n=closing')[1])->toBeTrue();
        native_stop($process);
    }
    expect(log_count($log, '/CRITICAL/'))->toBe(0, file_get_contents($log));
});

test('hostile names through the master: binary, 64 KiB, unicode, 0', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        worker_pids($addr, 2, 10);
        $names = ['0', "\0", "a\0b", "\xff\xfe\x00", 'ünï©ode 🙂', "a\r\nb", str_repeat('x', 65536), str_repeat("\0", 65536), str_repeat('é', 70000), '1', '01'];
        $holders = [];
        foreach ($names as $i => $name) {
            expect(claim_post($addr, '/claim-raw?op=available', $name)[1])->toBeTrue("free: $i");
            [$pid, $got] = claim_post($addr, '/claim-raw?op=claim', $name);
            expect($got)->toBeTrue("claim $i");
            $holders[$i] = $pid;
        }
        foreach ($names as $i => $name) {
            expect(claim_post($addr, '/claim-raw?op=available', $name)[1])->toBeFalse("held: $i");
            // Another worker's attempt is refused; its own, a new handle, as well
            for ($try = 0; $try < 6; ++$try) {
                expect(claim_post($addr, '/claim-raw?op=claim', $name)[1])->toBeFalse("refused: $i");
            }
        }
        expect(claim_post($addr, '/claim-raw?op=available', str_repeat('x', 65535))[1])->toBeTrue(); // one byte shorter: another name
        foreach ($names as $i => $name) {
            $released = false;
            for ($try = 0; $try < 100 && !$released; ++$try) { // the holder is the one that can release it
                $released = claim_post($addr, '/claim-raw?op=release', $name)[1];
            }
            expect($released)->toBeTrue("release $i");
            expect(claim_post($addr, '/claim-raw?op=available', $name)[1])->toBeTrue("freed: $i");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

/*
 * 4. Interaction.
 */

test('claim names are apart from cache keys: the same string in both, and a cache clear frees no claim', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        expect(cache_call($addr, '/cache-set?k=shared&v=' . urlencode('"cached"'))[1])->toBeTrue();
        expect(cache_call($addr, '/available?n=shared')[1])->toBeTrue(); // a cache key is no claim
        expect(cache_call($addr, '/claim?n=shared')[1])->toBeTrue();
        expect(cache_call($addr, '/cache-get?k=shared')[1])->toBe('cached'); // and a claim leaves the cache alone
        expect(cache_call($addr, '/available?n=shared')[1])->toBeFalse();

        expect(cache_call($addr, '/cache-del?k=shared')[1])->toBeTrue();
        expect(cache_call($addr, '/available?n=shared')[1])->toBeFalse();
        expect(cache_call($addr, '/cache-set?k=shared&v=1')[1])->toBeTrue();
        expect(cache_call($addr, '/cache-clear')[1])->toBeTrue();
        expect(cache_call($addr, '/cache-get?k=shared')[1])->toBe('missing');
        for ($i = 0; $i < 6; ++$i) { // however many workers: still held
            expect(cache_call($addr, '/available?n=shared')[1])->toBeFalse();
            expect(cache_call($addr, '/claim?n=shared')[1])->toBeFalse();
        }
        $released = false;
        for ($i = 0; $i < 100 && !$released; ++$i) {
            $released = cache_call($addr, '/release?n=shared')[1];
        }
        expect($released)->toBeTrue();
        expect(cache_call($addr, '/cache-set?k=shared&v=2')[1])->toBeTrue();
        expect(cache_call($addr, '/available?n=shared')[1])->toBeTrue();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('without a master a cache clear frees no claim, and a claim leaves the cache alone', function () {
    $cache = Swerve::cache();
    $cache->set('local-shared', 1);
    $claim = Swerve::claim('local-shared');
    expect($claim->acquire())->toBe($claim);
    $cache->clear();
    expect($claim->held())->toBeTrue();
    expect($cache->get('local-shared'))->toBeNull();
    $cache->set('local-shared', 2);
    expect($cache->get('local-shared'))->toBe(2);
    $claim->release();
    expect($cache->get('local-shared'))->toBe(2);
    expect(Swerve::claim('local-shared')->available())->toBeTrue();
});

/*
 * 5. The entries and the directory.
 */

test('the master creates the directory (0700) before it forks, every worker uses it, and it is gone when the master exits', function () {
    [$process, $addr, $log] = swerve_start(workers: 3);
    try {
        $dirs = [];
        foreach (range(1, 30) as $i) {
            $dirs[cache_call($addr, '/claim-dir')[1]] = true;
        }
        expect(count($dirs))->toBe(1);
        $dir = array_key_first($dirs);
        expect(str_starts_with($dir, sys_get_temp_dir() . '/swerve-claims-'))->toBeTrue();
        expect(substr(sprintf('%o', fileperms($dir)), -4))->toBe('0700');
        expect(cache_call($addr, '/claim?n=entry')[1])->toBeTrue();
        $entries = glob("$dir/[0-9a-f]*");
        expect(array_map('basename', $entries))->toBe([hash('sha256', 'entry')]);
        expect(file_get_contents($entries[0]))->toBe(substr(basename(glob("$dir/~*")[0]), 1)); // the holder's pid, in a file of its own
    } finally {
        native_stop($process);
    }
    expect(file_exists($dir))->toBeFalse(); // with the entries of the handles that were still held
});

test('a master stopped by SIGTERM with claims held leaves no directory behind', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    worker_pids($addr, 2, 10);
    $dir = cache_call($addr, '/claim-dir')[1];
    for ($i = 0; $i < 6; ++$i) {
        cache_call($addr, "/claim?n=kept$i");
    }
    expect(count(glob("$dir/[0-9a-f]*")))->toBe(6);
    swerve_signal($process, SIGTERM);
    [$code] = swerve_wait($process, 10);
    expect([$code, file_exists($dir)])->toBe([0, false]);
});

test('Claim::clear() removes the entries and pid file of one pid only, never those of another that shares its digits', function () {
    $dir = Claim::directory();
    $mine = [];
    foreach (['424242' => 3, '4242421' => 1, '42424' => 1, '24242' => 1, '0' => 1] as $pid => $count) {
        for ($i = 0; $i < $count; ++$i) {
            $path = "$dir/clear-$pid-$i";
            file_put_contents($path, (string) $pid);
            $mine[$path] = (string) $pid;
        }
    }
    Claim::clear(424242);
    foreach ($mine as $path => $pid) {
        expect(file_exists($path))->toBe('424242' !== $pid, "$path");
    }
    foreach (array_keys($mine) as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

test('without a master, a handle whose entry belongs to another pid does not unlink it, on release or destruct', function () {
    $stale = Swerve::claim('foreign');
    expect($stale->acquire())->toBe($stale);
    $path = claim_path('foreign');
    unlink($path);              // as if it had been cleared, and another process took the name
    file_put_contents($path, '1');
    $stale->release();
    expect(file_get_contents($path))->toBe('1');
    expect($stale->held())->toBeFalse();
    expect(Swerve::claim('foreign')->acquire())->toBeNull(); // still held, by the other

    (function () {
        $handle = Swerve::claim('foreign-2');
        $handle->acquire();
        unlink(claim_path('foreign-2'));
        file_put_contents(claim_path('foreign-2'), '1');
    })(); // destructed here: the entry is not ours
    expect(file_get_contents(claim_path('foreign-2')))->toBe('1');
    unlink($path);
    unlink(claim_path('foreign-2'));
});

test('without a master, a handle inherited by a forked process does not free the parent\'s claim', function () {
    $claim = Swerve::claim('forked');
    expect($claim->acquire())->toBe($claim);
    $pid = pcntl_fork();
    if (0 === $pid) {
        $claim->release();
        posix_kill(getmypid(), SIGKILL); // no shutdown: nothing of the parent's to run twice
    }
    pcntl_waitpid($pid, $status);
    expect(file_get_contents(claim_path('forked')))->toBe((string) getmypid());
    expect(Swerve::claim('forked')->available())->toBeFalse();
    expect(is_dir(Claim::directory()))->toBeTrue(); // and the child did not remove the directory
    $claim->release();
    expect(Swerve::claim('forked')->available())->toBeTrue();
});

test('without a master, a hot loop of acquire and release leaves the directory as it was; an entry holds its holder\'s pid', function () {
    $before = claims_here();
    $claim  = Swerve::claim('hot');
    for ($i = 0; $i < 2000; ++$i) {
        expect($claim->acquire())->toBe($claim);
        if (0 === $i) {
            expect(file_get_contents(claim_path('hot')))->toBe((string) getmypid());
        }
        $claim->release();
        $other = Swerve::claim('hot');
        expect($other->available())->toBeTrue();
        expect($other->held())->toBeFalse();
    }
    expect(claims_here())->toBe($before);
    expect(substr(sprintf('%o', fileperms(Claim::directory())), -4))->toBe('0700');
});

test('without a master, a process that ends holding a claim is silent and takes its directory with it', function () {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    $script   = 'require ' . var_export($autoload, true) . '; $c = Swerve\Swerve::claim("x"); $c->acquire(); echo Swerve\Claim::directory();';
    $output   = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);
    expect($code)->toBe(0);
    expect(count($output))->toBe(1, implode("\n", $output)); // the directory, and no warning after it
    expect(file_exists($output[0]))->toBeFalse();
});
