<?php

use Swerve\Swerve;

/*
 * publish() and the worker's inbox never fail because a subscriber left meanwhile: delivery
 * suspends on the topic's publisher, and the last subscriber leaving closes it (issue #20).
 */

test('publish() does not throw when the last subscriber leaves while it delivers', function () {
    $outcome = phasync::run(function () {
        $received = [];
        phasync::go(function () use (&$received) {
            foreach (Swerve::subscribe('t') as $message) {
                $received[] = $message;
                break;
            }
        });
        $published = phasync::go(function () {
            phasync::sleep(0);
            Swerve::publish('t', 'a');
            Swerve::publish('t', 'b');

            return 'published';
        });

        return [phasync::await($published), $received];
    });

    expect($outcome)->toBe(['published', ['a']]);
});

test('a drain while publish() delivers does not make it throw', function () {
    $outcome = phasync::run(function () {
        $subscription = Swerve::subscribe('t');
        $published    = phasync::go(function () {
            Swerve::publish('t', 'a');

            return 'published';
        });
        \Swerve\Util\Topics::drain();
        $result = phasync::await($published);
        unset($subscription);

        return $result;
    });
    \Swerve\Util\Topics::$draining = false;

    expect($outcome)->toBe('published');
});

function leave_subscribe(string $addr, string $topic, int $n)
{
    $conn = native_connect($addr);
    fwrite($conn, "GET /subscribe?topic=$topic&n=$n HTTP/1.1\r\nHost: t\r\n\r\n");
    native_read_head($conn);
    native_read_chunk($conn); // ready <pid>

    return $conn;
}

test('in a cluster, subscribers that leave after one message do not stop any worker receiving, nor log an exception', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        for ($round = 0; $round < 25; ++$round) {
            $conns = [];
            for ($i = 0; $i < 8; ++$i) {
                $conns[] = leave_subscribe($addr, 'leave', 1);
            }
            // A burst: the later messages reach workers whose subscribers have left or are leaving
            expect(probe($addr, '/publish-seq?topic=leave&id=x&count=20&sleep=0'))->toMatch('/^\d+$/');
            foreach ($conns as $conn) {
                expect(native_read_chunk($conn))->toStartWith('data: x:');
                fclose($conn);
            }
        }
        // Every worker still gets what is published
        $conns = [];
        for ($i = 0; $i < 12; ++$i) {
            $conns[] = leave_subscribe($addr, 'leave', 1);
        }
        probe($addr, '/publish?topic=leave&m=last');
        foreach ($conns as $conn) {
            expect(native_read_chunk($conn))->toBe("data: last\n\n");
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/Unhandled|ChannelException|error|critical/'))->toBe(0, file_get_contents($log));
});
