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

test('publish() refuses a topic of 0 or over 255 bytes, and a message over 1 MiB', function (string $topic, int $size) {
    expect(static fn () => Swerve::publish($topic, str_repeat('x', $size)))->toThrow(InvalidArgumentException::class);
})->with([
    'empty topic'   => ['', 1],
    'long topic'    => [str_repeat('t', 256), 1],
    'large message' => ['t', (1 << 20) + 1],
]);

test('frames survive being read in pieces, among status bytes', function () {
    $stream = '.R' . Topics::frame('chat', 'hello') . 'T' . Topics::frame(str_repeat('t', 255), str_repeat('m', 70000)) . '.';
    $buffer = '';
    $status = '';
    $got    = [];
    foreach (str_split($stream, 7) as $piece) {
        $buffer .= $piece;
        $status .= Topics::parse($buffer, static function (string $topic, string $message, string $frame) use (&$got) {
            $got[] = [strlen($topic), $message === 'hello' ? 'hello' : strlen($message), strlen($frame)];
        });
    }

    expect([$status, $got, $buffer])->toBe(['.RT.', [[4, 'hello', 23], [255, 70000, 70269]], '']);
});

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
        foreach (['one', 'two', 'three'] as $m) {
            expect(probe($addr, "/publish?topic=room&m=$m"))->toBe('published');
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

test('the master kills a worker that leaves published messages unread for 30 s, also with the watchdog off', function () {
    [$process, $addr, $log] = swerve_start(['--watchdog=0'], 2);
    try {
        // Stalls one worker's event loop for longer than that
        $busy = native_connect($addr);
        fwrite($busy, "GET /busy?s=45 HTTP/1.1\r\nHost: t\r\n\r\n");
        usleep(300000);
        // 4 MiB, more than its pipe holds, published through the other worker; a probe that
        // lands on the stalled one times out
        $published = 0;
        $deadline  = microtime(true) + 10;
        while ($published < 8 && microtime(true) < $deadline) {
            $published += (int) ('published' === probe($addr, '/publish?topic=room&m=x&times=524288', 0.5));
        }
        expect($published)->toBe(8);
        $start = microtime(true);
        log_wait($log, '/Killing worker \d+ \(slot \d\): published messages unread for 30 s/', 40);
        expect(microtime(true) - $start)->toBeGreaterThan(25);
        expect(probe($addr, '/hello'))->toBe('Hello');
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

test('a drain ends every subscription\'s loop, and one made while draining ends at once', function () {
    $got = phasync::run(function () {
        $ended = [];
        foreach (['a', 'b'] as $name) {
            $subscription = Swerve::subscribe('t');
            phasync::go(static function () use ($subscription, $name, &$ended) {
                foreach ($subscription as $message) {
                }
                $ended[] = $name;
            });
        }
        unset($subscription);
        phasync::sleep(0.01);
        Topics::drain();
        phasync::sleep(0.01);
        foreach (Swerve::subscribe('t') as $message) {
            $ended[] = 'got a message';
        }
        $ended[] = 'new one ended';

        return [$ended, Swerve::draining(), Topics::active()];
    });
    Topics::$draining = false; // this test process goes on

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
        expect(probe($addr, '/publish-json?topic=structured&m=hello'))->toBe('published');
        expect(probe($addr, '/publish?topic=structured&m=plain'))->toBe('published');
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

test('without a master, a message is delivered as JSON would deliver it, strings as strings; null is refused', function () {
    $received = phasync::run(static function () {
        $subscription = Swerve::subscribe('local-structured');
        Swerve::publish('local-structured', ['a' => 1.0, 'o' => (object) ['x' => 1]]);
        foreach ($subscription as $message) {
            return $message;
        }
    });
    expect($received)->toBe(['a' => 1.0, 'o' => ['x' => 1]]); // objects arrive as arrays, as from another worker
    $strings = phasync::run(static function () {
        $subscription = Swerve::subscribe('local-strings');
        Swerve::publish('local-strings', '{}');
        Swerve::publish('local-strings', '"quoted"');
        $got = [];
        foreach ($subscription as $message) {
            $got[] = $message;
            if (2 === \count($got)) {
                return $got;
            }
        }
    });
    expect($strings)->toBe(['{}', '"quoted"']); // a string stays exactly that string
    expect(fn () => Swerve::publish('local-structured', null))->toThrow(InvalidArgumentException::class);

    // What a worker could not decode fails in the publisher: 512 levels encode, but don't decode
    $deep = 'x';
    for ($i = 0; $i < 512; ++$i) {
        $deep = [$deep];
    }
    expect(fn () => Swerve::publish('local-structured', $deep))->toThrow(JsonException::class);
    expect(fn () => Swerve::publish('local-structured', "\xFF"))->toThrow(JsonException::class); // invalid UTF-8
});

test('a frame carries when it was published, so the master can forward frames from several workers in publishing order', function () {
    $buffer = Topics::frame('a', 'first') . Topics::frame('b', 'second');
    $stamps = [];
    Topics::parse($buffer, static function (string $topic, string $message, string $frame, bool $json, int $published) use (&$stamps) {
        $stamps[$message] = $published;
    });
    expect(\array_keys($stamps))->toBe(['first', 'second']);
    expect($stamps['first'])->toBeLessThanOrEqual($stamps['second'])->toBeGreaterThan(0);
});
