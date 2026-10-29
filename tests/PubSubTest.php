<?php

use Swerve\SubscriberLagException;
use Swerve\Swerve;
use Swerve\Util\Topics;

/*
 * Swerve::publish() and Swerve::subscribe(): in this process (no master, as when swerve is
 * embedded), then across the workers of a cluster.
 */

test('without a master, subscribers of a topic receive what is published to it, in order, and only them', function () {
    $got = phasync::run(function () {
        $a     = Swerve::subscribe('t');
        $b     = Swerve::subscribe('t');
        $other = Swerve::subscribe('u');
        $got   = [];
        $read  = static function ($subscription, string $name, int $n) use (&$got) {
            return phasync::go(static function () use ($subscription, $name, $n, &$got) {
                foreach ($subscription as $message) {
                    $got[$name][] = $message;
                    if (count($got[$name]) === $n) {
                        break;
                    }
                }
            });
        };
        $readers = [$read($a, 'a', 3), $read($b, 'b', 3), $read($other, 'u', 1)];
        unset($a, $b, $other);
        foreach (['1', '2', '3'] as $message) {
            Swerve::publish('t', $message);
        }
        Swerve::publish('u', 'x');
        foreach ($readers as $reader) {
            phasync::await($reader);
        }
        ksort($got);

        return $got;
    });

    expect($got)->toBe(['a' => ['1', '2', '3'], 'b' => ['1', '2', '3'], 'u' => ['x']]);
});

test('a subscription receives what is published from the moment it was created, not from its first iteration', function () {
    $got = phasync::run(function () {
        $subscription = Swerve::subscribe('t');
        Swerve::publish('t', 'before the loop');
        foreach ($subscription as $message) {
            return $message;
        }
    });

    expect($got)->toBe('before the loop');
});

test('a topic ends with its last subscription: after a break, when the coroutine holding it ends, and not before', function () {
    $seen = phasync::run(function () {
        $seen = [];
        phasync::go(static function () {
            phasync::sleep(0.01);
            Swerve::publish('t', 'first');
        });
        foreach (Swerve::subscribe('t') as $message) {
            break;
        }
        $seen['received one, then left'] = Topics::active();

        Swerve::publish('t', 'nobody listens'); // dropped
        $first  = Swerve::subscribe('t');
        $second = Swerve::subscribe('t');
        Swerve::publish('t', 'm');
        unset($first);
        $seen['one of two left'] = Topics::active();
        foreach ($second as $message) {
            $seen['the other still receives'] = $message;
            break;
        }
        unset($second);
        $seen['both left'] = Topics::active();

        phasync::await(phasync::go(static function () {
            $held = Swerve::subscribe('t');
            phasync::sleep(0.01);
        }));
        $seen['its coroutine ended'] = Topics::active();

        $again = Swerve::subscribe('t');
        Swerve::publish('t', 'again');
        foreach ($again as $message) {
            $seen['subscribed again'] = $message;
            break;
        }

        return $seen;
    });

    expect($seen)->toBe([
        'received one, then left'     => [],
        'one of two left'             => ['t'],
        'the other still receives'    => 'm',
        'both left'                   => [],
        'its coroutine ended'         => [],
        'subscribed again'            => 'again',
    ]);
});

test('a subscriber that falls further behind than its maxLag gets SubscriberLagException from the loop', function () {
    $got = phasync::run(function () {
        $subscription = Swerve::subscribe('t', maxLag: 0.05);
        Swerve::publish('t', 'fresh');
        $got = [];
        try {
            foreach ($subscription as $message) {
                $got[] = $message;
                Swerve::publish('t', 'stale');
                phasync::sleep(0.1);
            }
        } catch (SubscriberLagException $e) {
            $got[] = $e->getMessage();
        }

        return $got;
    });

    expect($got[0])->toBe('fresh');
    expect($got[1])->toMatch('/^A subscriber of "t" fell 0\.1 s behind, more than its 0\.1 s$/');
});

test('publish() refuses a topic of 0 or over 255 bytes, and a message over 128 KiB', function (string $topic, int $size) {
    expect(static fn () => Swerve::publish($topic, str_repeat('x', $size)))->toThrow(InvalidArgumentException::class);
})->with([
    'empty topic'   => ['', 1],
    'long topic'    => [str_repeat('t', 256), 1],
    'large message' => ['t', (1 << 17) + 1],
]);

/**
 * Open a /subscribe stream, and read up to its "ready <pid>" event.
 *
 * @return array{0: resource, 1: int} the connection and the worker's pid
 */
function pubsub_subscribe(string $addr, string $topic, int $n)
{
    $conn = native_connect($addr);
    fwrite($conn, "GET /subscribe?topic=$topic&n=$n HTTP/1.1\r\nHost: t\r\n\r\n");
    native_read_head($conn);
    preg_match('/^data: ready (\d+)\n\n$/', (string) native_read_chunk($conn), $m);

    return [$conn, (int) $m[1]];
}

test('in a cluster, a message published in one worker reaches the subscribers in every worker, in order', function (int $workers) {
    [$process, $addr] = swerve_start([], $workers);
    try {
        $subscribers = [];
        $pids        = [];
        // The kernel spreads connections over the workers: 8 land in one of 4 once in 16384
        for ($i = 0; $i < 8; ++$i) {
            [$conn, $pid]  = pubsub_subscribe($addr, 'room', 3);
            $subscribers[] = $conn;
            $pids[$pid]    = true;
        }
        expect(count($pids))->toBeGreaterThanOrEqual(min(2, $workers));
        // One connection, publishing one after another: every subscriber gets them in that order
        $publisher = native_connect($addr);
        foreach (['one', 'two', 'three'] as $m) {
            fwrite($publisher, "GET /publish?topic=room&m=$m HTTP/1.1\r\nHost: t\r\n\r\n");
            $head = native_read_head($publisher);
            expect(fread($publisher, (int) $head['headers']['content-length']))->toBe('published');
        }
        foreach ($subscribers as $conn) {
            $events = '';
            while (null !== ($chunk = native_read_chunk($conn)) && '' !== $chunk) {
                $events .= $chunk;
            }
            expect($events)->toBe("data: one\n\ndata: two\n\ndata: three\n\n");
        }
    } finally {
        native_stop($process);
    }
})->with(['one worker' => 1, 'four workers' => 4]);

test('publishers in different workers at once: every subscriber, in every worker, gets the messages in the same order', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        $subscribers = [];
        $pids        = [];
        for ($i = 0; $i < 8; ++$i) {
            [$conn, $pid]  = pubsub_subscribe($addr, 'order', 40);
            $subscribers[] = $conn;
            $pids[$pid]    = true;
        }
        expect(count($pids))->toBeGreaterThanOrEqual(2);
        // Two publishers, on connections of their own, likely in two workers, at the same time
        $publishers = [];
        foreach (['a', 'b'] as $id) {
            $conn = native_connect($addr);
            fwrite($conn, "GET /publish-seq?topic=order&id=$id&count=20&sleep=0.2 HTTP/1.1\r\nHost: t\r\n\r\n");
            $publishers[] = $conn;
        }
        foreach ($publishers as $conn) {
            native_read_head($conn);
        }
        $orders = [];
        foreach ($subscribers as $conn) {
            $events = '';
            while (null !== ($chunk = native_read_chunk($conn)) && '' !== $chunk) {
                $events .= $chunk;
            }
            expect(substr_count($events, 'data: '))->toBe(40);
            $orders[$events] = true;
        }
        expect($orders)->toHaveCount(1);
    } finally {
        native_stop($process);
    }
});

test('a worker that falls a whole --pubsub-ring behind tells its subscribers they lost messages, logs it, and reads on', function () {
    [$process, $addr, $log] = swerve_start(['--pubsub-ring=16'], 2);
    try {
        [$subscriber, $pid] = pubsub_subscribe($addr, 'room', 1000);
        // A connection to the other worker, found before the subscriber's worker stalls
        for ($other = null; null === $other;) {
            $conn = native_connect($addr);
            fwrite($conn, "GET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
            $head = native_read_head($conn);
            if ((int) fread($conn, (int) $head['headers']['content-length']) !== $pid) {
                $other = $conn;
            }
        }
        // Stalls the subscriber's worker: a connection that asks for its pid, until it lands there
        for ($busy = null; null === $busy;) {
            $conn = native_connect($addr);
            fwrite($conn, "GET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
            $head = native_read_head($conn);
            if ((int) fread($conn, (int) $head['headers']['content-length']) === $pid) {
                fwrite($conn, "GET /busy?s=1 HTTP/1.1\r\nHost: t\r\n\r\n");
                $busy = $conn;
            }
        }
        usleep(200000);
        fwrite($other, "GET /publish-seq?topic=room&id=x&count=40&sleep=0 HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($other);
        expect((int) fread($other, (int) $head['headers']['content-length']))->not->toBe($pid);
        expect(native_read_head($busy)['status'])->toBe(200);
        $events = '';
        while (!str_contains($events, "data: lag\n\n") && null !== ($chunk = native_read_chunk($subscriber))) {
            $events .= $chunk;
        }
        expect($events)->toBe("data: lag\n\n");
        log_wait($log, '/fell more than the ring of 16 messages behind/', 5);
        // The worker reads on: a new subscriber there gets what comes next
        [$again] = pubsub_subscribe($addr, 'room', 1);
        expect(probe($addr, '/publish?topic=room&m=tail'))->toBe('published');
        expect(native_read_chunk($again))->toBe("data: tail\n\n");
    } finally {
        native_stop($process);
    }
});

test('after reloads, subscribers of the new workers get what is published', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        for ($round = 1; $round <= 3; ++$round) {
            $subscribers = [];
            for ($i = 0; $i < 4; ++$i) {
                [$subscribers[]] = pubsub_subscribe($addr, 'room', 1);
            }
            expect(probe($addr, "/publish?topic=room&m=round$round"))->toBe('published');
            foreach ($subscribers as $conn) {
                expect(native_read_chunk($conn))->toBe("data: round$round\n\n");
            }
            swerve_signal($process, SIGHUP);
            $deadline = microtime(true) + 10;
            while (substr_count((string) file_get_contents($log), 'Reload complete') < $round) {
                expect(microtime(true))->toBeLessThan($deadline);
                usleep(50000);
            }
        }
        expect(file_get_contents($log))->not->toContain('dropping');
    } finally {
        native_stop($process);
    }
});

test('with a heartbeat, the loop also gets null after that long without a message', function () {
    $got = phasync::run(function () {
        // phasync checks timeouts coarsely (docs/SEMANTICS.md, TMO-3): up to 0.5 s late
        phasync::go(static function () {
            phasync::sleep(1.2);
            Swerve::publish('t', 'later');
        });
        $got = [];
        foreach (Swerve::subscribe('t', heartbeat: 0.1) as $message) {
            $got[] = $message;
            if (null !== $message) {
                break;
            }
        }

        return $got;
    });

    expect($got)->toContain(null)->toContain('later');
    expect(end($got))->toBe('later');
});

test('a drain ends every subscription\'s loop with a WorkerStoppingException, and one made while draining ends at once', function () {
    $got = phasync::run(function () {
        $ended = [];
        foreach (['a', 'b'] as $name) {
            $subscription = Swerve::subscribe('t');
            phasync::go(static function () use ($subscription, $name, &$ended) {
                try {
                    foreach ($subscription as $message) {
                    }
                } catch (\Swerve\WorkerStoppingException) {
                    $ended[] = $name;
                }
            });
        }
        unset($subscription);
        phasync::sleep(0.01);
        Topics::drain(\Swerve\StopReason::Shutdown);
        phasync::sleep(0.01);
        foreach (Swerve::subscribe('t') as $message) {
            $ended[] = 'got a message';
        }
        $ended[] = 'new one ended';

        return [$ended, Swerve::draining(), Topics::active()];
    });
    Topics::$draining = Topics::$closed = false; // this test process goes on
    Topics::$stopping = null;

    expect($got)->toBe([['a', 'b', 'new one ended'], true, []]);
});

test('a message reaches every subscriber in every worker as the value published: an array as an array, a string as that string', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        $subscribers = [];
        $pids        = [];
        for ($i = 0; $i < 8; ++$i) {
            [$conn, $pid]  = pubsub_subscribe($addr, 'structured', 2);
            $subscribers[] = $conn;
            $pids[$pid]    = true;
        }
        expect(count($pids))->toBe(2);
        // One connection, so both publishes are from the same worker: see the note in the test above
        $publisher = native_connect($addr);
        foreach (['/publish-json?topic=structured&m=hello', '/publish?topic=structured&m=plain'] as $path) {
            fwrite($publisher, "GET $path HTTP/1.1\r\nHost: t\r\n\r\n");
            $head = native_read_head($publisher);
            expect(fread($publisher, (int) $head['headers']['content-length']))->toBe('published');
        }
        foreach ($subscribers as $conn) {
            $events = '';
            while (null !== ($chunk = native_read_chunk($conn)) && '' !== $chunk) {
                $events .= $chunk;
            }
            // An array arrives as an array (re-encoded here to show it), a string as the string
            expect($events)->toBe("data: json {\"m\":\"hello\",\"n\":1,\"list\":[1,2]}\n\ndata: plain\n\n");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(error|critical)/'))->toBe(0, file_get_contents($log));
});

test('without a master, a message arrives as the value published: bytes, arrays, and objects, a copy for each subscriber; null and closures are refused', function () {
    $bytes = \implode('', \array_map('chr', \range(0, 255))); // every byte value once
    [$got, $first, $second] = phasync::run(static function () use ($bytes) {
        $a = Swerve::subscribe('local-values');
        $b = Swerve::subscribe('local-values');
        Swerve::publish('local-values', $bytes);
        Swerve::publish('local-values', ['a' => 1.0, 'list' => [1, 2]]);
        Swerve::publish('local-values', (object) ['n' => 1]);
        $got = [];
        foreach ($a as $message) {
            $got[] = $message;
            if (3 === \count($got)) {
                break;
            }
        }
        $got[2]->n = 2; // one subscriber changes its copy
        $other = [];
        foreach ($b as $message) {
            $other[] = $message;
            if (3 === \count($other)) {
                break;
            }
        }

        return [$got, $got[2], $other[2]];
    });
    expect($got[0])->toBe($bytes);
    expect($got[1])->toBe(['a' => 1.0, 'list' => [1, 2]]);
    expect([$first->n, $second->n])->toBe([2, 1]); // the other's copy is its own
    expect(fn () => Swerve::publish('local-values', null))->toThrow(InvalidArgumentException::class);
    expect(fn () => Swerve::publish('local-values', static fn () => 1))->toThrow(InvalidArgumentException::class, 'must be serializable');
    // Any class, found by the autoloader; one it can't find is an Error in the subscriber's loop
    expect(phasync::run(static function () {
        $s = Swerve::subscribe('local-classes');
        Swerve::publish('local-classes', new ArrayObject([1, 2]));
        foreach ($s as $m) {
            return $m->getArrayCopy();
        }
    }))->toBe([1, 2]);
    expect(fn () => phasync::run(static function () {
        $s = Swerve::subscribe('local-missing');
        \Swerve\Util\Topics::deliver(1, \hrtime(true), 'local-missing', 'O:12:"NoSuchClass1":0:{}');
        foreach ($s as $m) {
            return $m;
        }
    }))->toThrow(Error::class, 'Class "NoSuchClass1" not found');
    expect(ini_get('unserialize_callback_func'))->toBe(''); // the application's unserialize() is as PHP made it
    // A string that merely looks like an object is a string
    expect(phasync::run(static function () {
        $s = Swerve::subscribe('local-strings');
        Swerve::publish('local-strings', 'O:11:"ArrayObject":0:{}');
        foreach ($s as $m) {
            return $m;
        }
    }))->toBe('O:11:"ArrayObject":0:{}');
});

test('in a cluster, bytes published in one worker reach a subscriber in another worker unchanged, invalid UTF-8 included', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        $bytes = \implode('', \array_map('chr', \range(0, 255)));
        $conn  = native_connect($addr);
        \fwrite($conn, "GET /subscribe?topic=bytes&n=1&hex=1 HTTP/1.1\r\nHost: t\r\n\r\n");
        native_read_head($conn);
        native_read_chunk($conn); // "ready <pid>"

        expect(probe($addr, '/publish-bytes?topic=bytes&m=' . \bin2hex($bytes)))->toBe('published');

        \preg_match('/^data: ([0-9a-f]*)\n\n$/', (string) native_read_chunk($conn), $m);
        expect(\hex2bin($m[1]))->toBe($bytes);
    } finally {
        native_stop($process);
    }
});

