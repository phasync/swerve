<?php

/*
 * Swerve::claim() under attack: the master's table on its own, handles without a master,
 * mutual exclusion under contention, and what happens to claims as workers drain, die or stop.
 * The basics are in ClaimTest.php.
 */

use Swerve\Cache;
use Swerve\Claim;
use Swerve\Swerve;
use Swerve\Util\Claims;

/** @return array<string, array{0: string, 1: int}> the master's table of claims */
function claims_held(Claims $claims): array
{
    return (new ReflectionProperty(Claims::class, 'held'))->getValue($claims);
}

/** How many claims this process holds, without a master, once the garbage of earlier tests (handles in cycles) is gone. */
function claims_here(): int
{
    gc_collect_cycles();
    $claims = (new ReflectionProperty(Cache::class, 'claims'))->getValue(Cache::instance());

    return null === $claims ? 0 : count(claims_held($claims));
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
 * 1. The master's table, Claims::apply() on its own.
 */

test('Claims::apply: claim, check, held and release, each by token', function () {
    $claims = new Claims();
    expect($claims->apply(['check', 'a'], 1))->toBeFalse();
    expect($claims->apply(['held', 'a', 't1'], 1))->toBeFalse();
    expect($claims->apply(['release', 'a', 't1'], 1))->toBeFalse(); // nothing to release

    expect($claims->apply(['claim', 'a', 't1'], 1))->toBeTrue();
    expect($claims->apply(['check', 'a'], 2))->toBeTrue();           // anyone can check
    expect($claims->apply(['held', 'a', 't1'], 1))->toBeTrue();
    expect($claims->apply(['held', 'a', 't2'], 1))->toBeFalse();     // another token: not held by it
    expect($claims->apply(['held', 'a', 't1'], 2))->toBeTrue();      // the token decides, not who asks

    expect($claims->apply(['claim', 'a', 't2'], 2))->toBeFalse();    // taken
    expect($claims->apply(['claim', 'a', 't2'], 1))->toBeFalse();    // also by the same worker, with a handle of its own
    expect($claims->apply(['claim', 'a', 't1'], 1))->toBeTrue();     // the same token again: still its own
    expect($claims->apply(['claim', 'b', 't2'], 1))->toBeTrue();     // another name is free

    expect($claims->apply(['release', 'a', 't2'], 1))->toBeFalse();  // a wrong token frees nothing
    expect($claims->apply(['release', 'a', ''], 1))->toBeFalse();
    expect($claims->apply(['check', 'a'], 1))->toBeTrue();
    expect($claims->apply(['held', 'a', 't1'], 1))->toBeTrue();

    expect($claims->apply(['release', 'a', 't1'], 1))->toBeTrue();
    expect($claims->apply(['release', 'a', 't1'], 1))->toBeFalse();  // twice
    expect($claims->apply(['check', 'a'], 1))->toBeFalse();
    expect($claims->apply(['held', 'a', 't1'], 1))->toBeFalse();
    expect($claims->apply(['claim', 'a', 't2'], 2))->toBeTrue();     // free for whoever is next
    expect(array_keys(claims_held($claims)))->toBe(['b', 'a']);
});

test('Claims::apply: a release of a token that never held does not disturb a name, or free it', function () {
    $claims = new Claims();
    expect($claims->apply(['release', 'x', 'nobody'], 1))->toBeFalse();
    expect(claims_held($claims))->toBe([]); // no phantom entry
    $claims->apply(['claim', 'x', 'owner'], 1);
    expect($claims->apply(['release', 'x', 'nobody'], 1))->toBeFalse();
    expect($claims->apply(['release', 'x', 'nobody'], 2))->toBeFalse();
    expect($claims->apply(['held', 'x', 'owner'], 1))->toBeTrue();
});

test('Claims::release($owner) frees what that owner holds, and nothing of the others', function () {
    $claims = new Claims();
    foreach (['a', 'b', 'c'] as $name) {
        expect($claims->apply(['claim', $name, "t-$name"], 1))->toBeTrue();
    }
    expect($claims->apply(['claim', 'd', 't-d'], 2))->toBeTrue();
    expect($claims->apply(['claim', 'e', 't-e'], 3))->toBeTrue();

    $claims->release(4); // nobody has this inbox
    expect(count(claims_held($claims)))->toBe(5);
    $claims->release(1);
    expect(array_keys(claims_held($claims)))->toBe(['d', 'e']);
    foreach (['a', 'b', 'c'] as $name) {
        expect($claims->apply(['check', $name], 2))->toBeFalse();
        expect($claims->apply(['claim', $name, "n-$name"], 2))->toBeTrue(); // taken over at once
    }
    $claims->release(1); // the inbox is gone again: the new holders keep what they took
    expect(count(claims_held($claims)))->toBe(5);
    expect($claims->apply(['held', 'a', 'n-a'], 2))->toBeTrue();

    // A late release by the old handle, which had a token of its own, frees nothing of the new holder's
    expect($claims->apply(['release', 'a', 't-a'], 1))->toBeFalse();
    expect($claims->apply(['held', 'a', 'n-a'], 2))->toBeTrue();
});

test('Claims::release($owner): an inbox reused by a replacement worker inherits nothing', function () {
    $claims = new Claims();
    $claims->apply(['claim', 'job', 'tokenA'], 7); // worker A, inbox 7
    $claims->apply(['claim', 'other', 'tokenA2'], 7);
    $claims->release(7);                           // A died; the master frees its claims at once
    expect(claims_held($claims))->toBe([]);

    // B starts with the same inbox id and takes one name; A's handle never held anything of B's
    expect($claims->apply(['held', 'job', 'tokenA'], 7))->toBeFalse();
    expect($claims->apply(['claim', 'job', 'tokenB'], 7))->toBeTrue();
    expect($claims->apply(['release', 'job', 'tokenA'], 7))->toBeFalse(); // A's late release, arriving as inbox 7
    expect($claims->apply(['held', 'job', 'tokenB'], 7))->toBeTrue();
    expect($claims->apply(['check', 'other'], 7))->toBeFalse();            // and B has not inherited A's other name
});

test('Claims::apply: hostile names are each their own name', function () {
    $names = [
        "\0", "\0\0", "a\0", "a\0b", "a", 'A', '0', '00', '-0', '0.0', '1', '01', ' 1', '1 ', '9223372036854775807', '9223372036854775808',
        '-9223372036854775808', 'null', 'false', 'Array', "\xff\xfe", "\xc3\x28", 'ünï©ode', '日本語', "🙂", "🙂 ", "a\nb", "a\r\nb", "a\tb",
        ' ', '  ', "\\", "'", '"', '%00', '../etc/passwd', str_repeat('x', 65536), str_repeat('x', 65535) . 'y', str_repeat("\0", 65536),
        str_repeat('é', 40000), str_repeat('a', 1 << 20),
    ];
    $claims = new Claims();
    foreach ($names as $i => $name) {
        expect($claims->apply(['check', $name], 1))->toBeFalse(substr(bin2hex(substr($name, 0, 20)), 0, 40));
        expect($claims->apply(['claim', $name, "t$i"], 1))->toBeTrue();
    }
    expect(count(claims_held($claims)))->toBe(count($names)); // no name collided with another
    foreach ($names as $i => $name) {
        expect($claims->apply(['held', $name, "t$i"], 1))->toBeTrue();
        expect($claims->apply(['held', $name, 'tX'], 1))->toBeFalse();
        expect($claims->apply(['claim', $name, 'tX'], 2))->toBeFalse();
    }
    foreach ($names as $i => $name) {
        expect($claims->apply(['release', $name, 'tX'], 1))->toBeFalse();
    }
    expect(count(claims_held($claims)))->toBe(count($names));
    foreach ($names as $i => $name) {
        expect($claims->apply(['release', $name, "t$i"], 1))->toBeTrue();
    }
    expect(claims_held($claims))->toBe([]);
});

test('Claims::apply: tokens are strings too, whatever they hold', function () {
    $claims = new Claims();
    foreach (['0', "\0", '', str_repeat('t', 65536), '1', '01'] as $token) {
        expect($claims->apply(['claim', 'k', $token], 1))->toBeTrue();
        foreach (['0', "\0", '', '1', '01', 'x'] as $other) {
            expect($claims->apply(['held', 'k', $other], 1))->toBe($other === $token);
            expect($claims->apply(['claim', 'k', $other], 2))->toBe($other === $token);
        }
        expect($claims->apply(['release', 'k', $token], 1))->toBeTrue();
    }
    expect(claims_held($claims))->toBe([]);
});

test('Claims::apply: 20 000 claims acquired and released leave the table empty', function () {
    $claims = new Claims();
    for ($i = 0; $i < 20_000; ++$i) {
        expect($claims->apply(['claim', "name-$i", "t$i"], $i % 4))->toBeTrue();
        $claims->apply(['claim', (string) $i, "n$i"], $i % 4); // integer-like names too
    }
    expect(count(claims_held($claims)))->toBe(40_000);
    for ($i = 0; $i < 20_000; ++$i) {
        if ($i % 2) {
            expect($claims->apply(['release', "name-$i", "t$i"], 0))->toBeTrue();
            expect($claims->apply(['release', (string) $i, "n$i"], 0))->toBeTrue();
        }
    }
    expect(count(claims_held($claims)))->toBe(20_000);
    for ($owner = 0; $owner < 4; ++$owner) {
        $claims->release($owner);
    }
    expect(claims_held($claims))->toBe([]);

    // And once more, all by one owner, in reverse
    for ($i = 0; $i < 20_000; ++$i) {
        $claims->apply(['claim', "again-$i", 't'], 9);
    }
    $claims->release(9);
    expect(claims_held($claims))->toBe([]);
});

/*
 * 1b. Handles without a master.
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

test('without a master, 20 000 handles acquired and released leave nothing behind', function () {
    $before = claims_here();
    for ($i = 0; $i < 20_000; ++$i) {
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
            posix_kill($holder, SIGKILL);

            $start = microtime(true);
            [$pid, $got] = claim_retry($addr, '/claim?n=job&timeout=8');
            expect($got)->toBeTrue("with $workers workers");
            expect($pid)->not->toBe($holder);
            expect(microtime(true) - $start)->toBeLessThan(4.0);

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

test('a SIGKILL of a worker frees all the names it holds, and only its own', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        worker_pids($addr, 2, 10);
        $by = [];
        for ($i = 0; $i < 40; ++$i) {
            [$pid, $got] = cache_call($addr, "/claim?n=multi$i");
            expect($got)->toBeTrue();
            $by[$pid][] = $i;
        }
        expect(count($by))->toBe(2);
        $victim = array_key_first($by);
        $other  = array_key_last($by);
        posix_kill($victim, SIGKILL);
        $deadline = microtime(true) + 10;
        while (($free = array_filter($by[$victim], static fn ($i) => claim_retry($addr, "/available?n=multi$i")[1])) !== $by[$victim]) {
            if (microtime(true) > $deadline) {
                break;
            }
            usleep(50_000);
        }
        expect($free)->toBe($by[$victim]);
        foreach ($by[$other] as $i) {
            expect(claim_retry($addr, "/available?n=multi$i")[1])->toBeFalse(); // the survivor keeps its own
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/CRITICAL/'))->toBe(0, file_get_contents($log));
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

test('SIGHUP rolling reloads x3 while a worker holds: freed at the drain, a new worker takes it, a late release changes nothing', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        worker_pids($addr, 2, 10);
        $keepAlive = native_connect($addr); // stays with the worker that answers it
        fwrite($keepAlive, "GET /claim?n=job HTTP/1.1\r\nHost: t\r\n\r\n");
        [$first, $got] = json_decode(native_read_response($keepAlive)['body'], true);
        expect($got)->toBeTrue();
        $holders = [$first];

        for ($round = 1; $round <= 3; ++$round) {
            swerve_signal($process, SIGHUP);
            log_wait_count($log, '/Reload complete/', $round, 15);
            expect(claim_retry($addr, '/available?n=job')[1])->toBeTrue("freed when its worker drained, round $round");
            if (1 === $round) { // the old worker, if its connection is still served, knows it holds nothing
                fwrite($keepAlive, "GET /held?n=job HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
                $response = native_read_response($keepAlive);
                if (null !== $response && 200 === $response['status']) {
                    expect(json_decode($response['body'], true))->toBe([$first, false]);
                }
            }
            [$pid, $got] = claim_retry($addr, '/claim?n=job');
            expect($got)->toBeTrue();
            expect($holders)->not->toContain($pid);
            $holders[] = $pid;
        }
        // The old workers exit, their handles destroyed: the late releases carry tokens that are not the holder's
        log_wait_count($log, '/exited after draining/', 6, 15);
        $last = end($holders);
        $held = null;
        for ($i = 0; $i < 300 && !$held; ++$i) {
            [$pid, $held] = cache_call($addr, '/held?n=job');
            if ($pid !== $last) {
                $held = null;
            }
        }
        expect($held)->toBeTrue();
        expect(cache_call($addr, '/available?n=job')[1])->toBeFalse();
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

test('a coroutine cancelled while its acquire is in flight does not leave the claim held', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        for ($i = 0; $i < 20; ++$i) {
            expect(cache_call($addr, "/claim-cancel?n=in-flight$i&mode=call")[1])->toBeTrue();
        }
        usleep(300_000);
        for ($i = 0; $i < 20; ++$i) {
            expect(cache_call($addr, "/available?n=in-flight$i")[1])->toBeTrue("claim in-flight$i was taken by a cancelled acquire and nothing releases it");
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

test('fire-and-forget releases among 1000 claim, held and available calls leave every answer right', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$pid, $bad] = cache_call($addr, '/claim-mix?count=1000&id=1');
        expect($bad)->toBe(0);
        [, $bad] = cache_call($addr, '/claim-mix?count=1000&id=2'); // again, on the same worker: ids kept counting
        expect($bad)->toBe(0);
        expect(cache_call($addr, '/available?n=mix-' . $pid . '-1-1-0')[1])->toBeTrue();
        expect(cache_call($addr, '/claim?n=after-mix')[1])->toBeTrue(); // the worker still talks to the master
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING)/i'))->toBe(0, file_get_contents($log));
});

test('fire-and-forget releases while four workers each run 1000 cycles', function () {
    [$process, $addr, $log] = swerve_start(workers: 4);
    try {
        worker_pids($addr, 4, 10);
        $results = claim_burst($addr, array_map(static fn ($id) => "/claim-mix?count=1000&id=$id", range(1, 8)), 60);
        foreach ($results as $result) {
            expect($result['json'][1])->toBe(0);
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL|WARNING)/i'))->toBe(0, file_get_contents($log));
});

test('a worker that held, died, and whose inbox is reused: the replacement inherits nothing and A\'s release is not its business', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$a, $got] = cache_call($addr, '/claim?n=job');
        expect($got)->toBeTrue();
        expect(cache_call($addr, '/claim?n=second')[1])->toBeTrue();
        posix_kill($a, SIGKILL);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $answer = probe($addr, '/available?n=job');
            $b      = null === $answer ? $a : json_decode($answer, true)[0];
        } while ($b === $a && microtime(true) < $deadline);
        expect($b)->not->toBe($a);
        expect(cache_call($addr, '/available?n=job')[1])->toBeTrue();    // not inherited
        expect(cache_call($addr, '/available?n=second')[1])->toBeTrue();
        expect(cache_call($addr, '/held?n=job')[1])->toBeFalse();

        expect(cache_call($addr, '/claim?n=job')[1])->toBeTrue();         // B takes it
        usleep(500_000);                                                   // nothing from A arrives late
        expect(cache_call($addr, '/available?n=job')[1])->toBeFalse();
        expect(cache_call($addr, '/held?n=job')[1])->toBeTrue();
    } finally {
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
