<?php

/*
 * Swerve::publish() never suspends the caller: the first publish to a topic in a
 * worker used to wait for the master's subscriber bitmap (Topics::bitmap()), so a publish in a
 * cancelled coroutine's finally (a WebSocket handler saying goodbye) threw CancelledException at
 * that wait, and the message was silently lost. See PubSubTest for publish()/subscribe() itself.
 */

/**
 * Open a /subscribe stream, and read up to its "ready <pid>" event.
 *
 * @return array{0: resource, 1: int} the connection and the worker's pid
 */
function nb_subscribe(string $addr, string $topic, int $n)
{
    $conn = native_connect($addr);
    fwrite($conn, "GET /subscribe?topic=$topic&n=$n HTTP/1.1\r\nHost: t\r\n\r\n");
    native_read_head($conn);
    preg_match('/^data: ready (\d+)\n\n$/', (string) native_read_chunk($conn), $m);

    return [$conn, (int) $m[1]];
}

/**
 * A connection to a worker other than $excludePid's: /pid first, kept open, on the first one that answers differently.
 *
 * @return resource
 */
function nb_other_worker(string $addr, int $excludePid)
{
    while (true) {
        $conn = native_connect($addr);
        fwrite($conn, "GET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($conn);
        if ((int) fread($conn, (int) $head['headers']['content-length']) !== $excludePid) {
            return $conn;
        }
    }
}

test('publish() in a cancelled coroutine\'s finally, on a topic never published before in its worker, still reaches a subscriber in another worker', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        [$subscriber, $subPid] = nb_subscribe($addr, 'goodbye-topic', 1);
        $other = nb_other_worker($addr, $subPid);

        fwrite($other, "GET /publish-from-cancelled?topic=goodbye-topic HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($other);
        expect(fread($other, (int) $head['headers']['content-length']))->toBe('done');

        expect(native_read_chunk($subscriber))->toBe("data: goodbye\n\n");
    } finally {
        native_stop($process);
    }
});

test('publishing a burst to a topic never published before in its worker queues it: a subscriber in another worker sees it in that order', function () {
    [$process, $addr] = swerve_start([], 4);
    try {
        [$subscriber, $subPid] = nb_subscribe($addr, 'burst-topic', 5);
        $other = nb_other_worker($addr, $subPid);

        fwrite($other, "GET /publish-burst?topic=burst-topic&n=5 HTTP/1.1\r\nHost: t\r\n\r\n");
        $head = native_read_head($other);
        expect(fread($other, (int) $head['headers']['content-length']))->toBe('done');

        $events = '';
        while (!str_contains($events, "data: msg4\n\n") && null !== ($chunk = native_read_chunk($subscriber))) {
            $events .= $chunk;
        }
        expect($events)->toBe("data: msg0\n\ndata: msg1\n\ndata: msg2\n\ndata: msg3\n\ndata: msg4\n\n");
    } finally {
        native_stop($process);
    }
});

test('publish() does not suspend its caller, even the first time this worker publishes to the topic', function () {
    [$process, $addr] = swerve_start([], 1);
    try {
        expect(probe($addr, '/publish-non-suspending?topic=never-used-topic'))->toBe('true');
    } finally {
        native_stop($process);
    }
});
