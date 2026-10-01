<?php

use Swerve\SubscriberLagException;
use Swerve\Swerve;
use Swerve\Util\Inboxes;
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

test('a worker that does not read its inbox does not hold up the publishers: they drop its messages with a warning, and it gets the later ones', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        [$subscriber, $pid] = pubsub_subscribe($addr, 'room', 1000);
        // A connection to the other worker, found before the subscriber's worker stalls: it publishes 800 KB, more than the stalled worker's inbox holds
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
                fwrite($conn, "GET /busy?s=2 HTTP/1.1\r\nHost: t\r\n\r\n");
                $busy = $conn;
            }
        }
        usleep(300000);
        for ($i = 0; $i < 8; ++$i) {
            fwrite($other, "GET /publish?topic=room&m=x&times=100000 HTTP/1.1\r\nHost: t\r\n\r\n");
            $head = native_read_head($other);
            expect(fread($other, (int) $head['headers']['content-length']))->toBe('published');
        }
        log_wait($log, '/Worker inbox \d+ is not read: dropping \d+ published messages for it/', 5);
        expect(native_read_head($busy)['status'])->toBe(200);
        expect(probe($addr, '/publish?topic=room&m=tail'))->toBe('published');
        $events = '';
        while (!str_contains($events, "data: tail\n\n") && null !== ($chunk = native_read_chunk($subscriber))) {
            $events .= $chunk;
        }
        expect($events)->toContain("data: tail\n\n");
        expect(file_get_contents($log))->not->toContain('Killing worker');
    } finally {
        native_stop($process);
    }
});

test('Inboxes keeps a bitmap per topic of the inboxes that subscribe, and tells to forget it before it answers', function () {
    $inboxes = new Inboxes(10);
    $forgot  = [];
    $forget  = static function (string $topic) use (&$forgot) {
        $forgot[] = $topic;
    };
    $id = pack('N', 7);

    expect($inboxes->serve(1, $id . 'ga', $forget))->toBe($id);
    expect($inboxes->serve(1, $id . '+a', $forget))->toBe($id . "\x02");
    expect($inboxes->serve(9, $id . '+a', $forget))->toBe($id . "\x02\x02");
    expect($inboxes->serve(9, $id . '+a', $forget))->toBe($id . "\x02\x02"); // again: nothing changed
    expect($inboxes->serve(1, $id . '+b', $forget))->toBe($id . "\x02");
    expect($forgot)->toBe(['a', 'a', 'b']);

    // Id 0 asks for no reply; unsubscribing trims the bitmap, and forgets only a change
    $forgot = [];
    expect($inboxes->serve(9, "\0\0\0\0-a", $forget))->toBeNull();
    expect($inboxes->serve(9, "\0\0\0\0-a", $forget))->toBeNull();
    expect($inboxes->serve(0, $id . 'ga', $forget))->toBe($id . "\x02");
    expect($forgot)->toBe(['a']);

    // An inbox that drains or is gone leaves every topic
    $forgot = [];
    $inboxes->leave(1, $forget);
    expect($forgot)->toBe(['a', 'b']);
    expect($inboxes->serve(0, $id . 'ga', $forget))->toBe($id);
    expect($inboxes->serve(0, $id . 'gb', $forget))->toBe($id);
});

test('Inboxes hands out each inbox once, empty, until it is released', function () {
    $inboxes = new Inboxes(2);
    expect([$inboxes->take(), $inboxes->take(), $inboxes->take()])->toBe([0, 1, null]);
    $inboxes->release(0);
    (fn () => stream_socket_sendto($this->write[0], 'left for its last process'))->call($inboxes);
    expect($inboxes->take())->toBe(0);
    expect((fn () => @stream_socket_recvfrom($this->read[0], Topics::MAX_DATAGRAM))->call($inboxes))->toBeFalse();
});

test('after reloads, which reuse the inboxes, subscribers of the new workers get what is published', function () {
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
    expect($received->a)->toBe(1.0); // an array with keys is a JSON object, as from another worker
    expect($received->o->x)->toBe(1);
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
