<?php

use Swerve\Swerve;
use Swerve\Util\Topics;

/*
 * Swerve::publishOrdered() and subscribeOrdered(): an append-only log on disk that every worker
 * appends to and every ordered subscriber reads, so that they all see one order. Across real
 * forked workers, then in this process (no master).
 */

/** Open a /subscribe stream (ordered, or plain), and read up to its "ready <pid>" event: [connection, the worker's pid]. */
function ord_subscribe(string $addr, string $topic, int $n, bool $ordered = true, string $extra = ''): array
{
    $conn = native_connect($addr);
    fwrite($conn, "GET /subscribe?topic=$topic&n=$n" . ($ordered ? '&ordered=1' : '') . "$extra HTTP/1.1\r\nHost: t\r\n\r\n");
    native_read_head($conn);
    preg_match('/^data: ready (\d+)\n\n$/', (string) native_read_chunk($conn), $m);

    return [$conn, (int) $m[1]];
}

/** The events of an SSE stream up to the $n-th, or its end. */
function ord_events($conn, int $n): array
{
    $text = '';
    while (substr_count($text, "\n\n") < $n && null !== ($chunk = native_read_chunk($conn)) && '' !== $chunk) {
        $text .= $chunk;
    }

    return array_map(static fn (string $event) => substr($event, 6), array_filter(explode("\n\n", $text), 'strlen'));
}

/** A persistent connection to the worker $pid (or any worker not in $not): [connection, pid]. */
function ord_connect(string $addr, ?int $pid = null, array $not = []): array
{
    for ($try = 0; $try < 100; ++$try) {
        $conn = native_connect($addr);
        fwrite($conn, "GET /pid HTTP/1.1\r\nHost: t\r\n\r\n");
        if (null === $head = native_read_head($conn)) {
            continue; // accepted by a worker that was killed
        }
        $got = (int) fread($conn, (int) $head['headers']['content-length']);
        if (($pid ?? $got) === $got && !in_array($got, $not, true)) {
            return [$conn, $got];
        }
        fclose($conn);
    }
    throw new RuntimeException('No connection to the wanted worker');
}

/** Start /publish-seq on a connection from ord_connect(); read its answer with ord_done(). */
function ord_publish($conn, string $topic, string $id, int $count, string $extra = ''): void
{
    fwrite($conn, "GET /publish-seq?topic=$topic&ordered=1&id=$id&count=$count$extra HTTP/1.1\r\nHost: t\r\n\r\n");
}

function ord_done($conn): void
{
    expect(native_read_head($conn)['status'])->toBe(200);
    fread($conn, 8);
}

/** The files of the ordered log's directory below $tmp, with their sizes. */
function ord_files(string $tmp): array
{
    $files = [];
    clearstatcache();
    foreach (glob("$tmp/swerve-ordered-*/*") as $file) {
        $files[basename($file)] = @filesize($file);
    }

    return $files;
}

function ord_wait(callable $until, float $timeout = 5.0): float
{
    $start = microtime(true);
    while (!$until()) {
        expect(microtime(true) - $start)->toBeLessThan($timeout);
        usleep(10000);
    }

    return microtime(true) - $start;
}

test('ordered subscribers in different workers get one identical sequence, and each publisher keeps its own order in it', function () {
    [$process, $addr, $log] = swerve_start([], 3);
    try {
        $subscribers = [];
        $pids        = [];
        for ($i = 0; $i < 9; ++$i) {
            [$subscribers[], $pid] = ord_subscribe($addr, 'o', 240);
            $pids[$pid]            = true;
        }
        expect(count($pids))->toBeGreaterThanOrEqual(2);

        $byPid = [];
        for ($i = 0; $i < 12; ++$i) {
            [$conn, $pid] = ord_connect($addr);
            if (count($byPid[$pid] ?? []) < 2) {
                $byPid[$pid][] = $conn;
            }
        }
        expect(count($byPid))->toBeGreaterThanOrEqual(2);
        $publishers = array_merge(...array_values($byPid));
        $per        = intdiv(240, count($publishers));
        foreach ($publishers as $id => $conn) {
            ord_publish($conn, 'o', "p$id", $per);
        }
        array_map('ord_done', $publishers);

        $total    = $per * count($publishers);
        $sequence = null;
        foreach ($subscribers as $conn) {
            $events = ord_events($conn, $total);
            expect(count($events))->toBe($total);
            $sequence ??= $events;
            expect($events)->toBe($sequence);
        }
        foreach ($publishers as $id => $_) {
            $own = array_values(array_filter($sequence, static fn (string $m) => str_starts_with($m, "p$id:")));
            expect($own)->toBe(array_map(static fn (int $i) => "p$id:$i:", range(0, $per - 1)));
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('plain and ordered subscriptions of one topic name do not see each other\'s messages', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        [$plain]   = ord_subscribe($addr, 't', 2, ordered: false);
        [$ordered] = ord_subscribe($addr, 't', 2);
        expect(probe($addr, '/publish?topic=t&m=p1'))->toBe('published');
        expect(probe($addr, '/publish-ordered?topic=t&m=o1'))->toBe('published');
        expect(probe($addr, '/publish?topic=t&m=p2'))->toBe('published');
        expect(probe($addr, '/publish-ordered?topic=t&m=o2'))->toBe('published');
        expect(ord_events($plain, 2))->toBe(['p1', 'p2']);
        expect(ord_events($ordered, 2))->toBe(['o1', 'o2']);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('nothing is written while the topic has no ordered subscriber: logging starts with the first, and stops after the last', function () {
    $tmp                    = temp_path(true);
    [$process, $addr, $log] = swerve_start([], 2, env: ['SWERVE_TMPDIR' => $tmp]);
    try {
        expect(probe($addr, '/publish-ordered?topic=x&m=unheard'))->toBe('published');
        expect(ord_files($tmp))->toBe([]);

        [$first] = ord_subscribe($addr, 'x', 1);
        expect(probe($addr, '/publish-ordered?topic=x&m=one'))->toBe('published');
        expect(ord_events($first, 1))->toBe(['one']);
        $size = array_sum(ord_files($tmp));
        expect($size)->toBeGreaterThan(0);
        expect(probe($addr, '/publish-ordered?topic=other&m=nobody'))->toBe('published');
        expect(array_sum(ord_files($tmp)))->toBe($size); // another topic's log is not started

        // The reader of the worker that had the subscriber ends within a second of it
        $stopped = ord_wait(static function () use ($addr, $tmp) {
            $before = array_sum(ord_files($tmp));
            probe($addr, '/publish-ordered?topic=x&m=drop');

            return array_sum(ord_files($tmp)) === $before;
        });
        expect($stopped)->toBeLessThan(3.0);
        $size = array_sum(ord_files($tmp));
        for ($i = 0; $i < 5; ++$i) {
            probe($addr, '/publish-ordered?topic=x&m=nobody');
        }
        expect(array_sum(ord_files($tmp)))->toBe($size);

        [$second] = ord_subscribe($addr, 'x', 1);
        expect(probe($addr, '/publish-ordered?topic=x&m=two'))->toBe('published');
        expect(ord_events($second, 1))->toBe(['two']);
        expect(array_sum(ord_files($tmp)))->toBeGreaterThan($size);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a worker killed after it appended loses nothing: subscribers of the other workers get its messages and then those of the others', function () {
    [$process, $addr, $log] = swerve_start([], 3);
    try {
        $subscribers = [];
        for ($i = 0; $i < 9; ++$i) {
            $subscribers[] = ord_subscribe($addr, 'k', 30);
        }
        $victim = $subscribers[0][1];
        expect(count(array_unique(array_column($subscribers, 1))))->toBeGreaterThanOrEqual(2);
        [$publisher] = ord_connect($addr, $victim);
        ord_publish($publisher, 'k', 'v', 20);
        ord_done($publisher);
        posix_kill($victim, SIGKILL);

        [$other] = ord_connect($addr, null, [$victim]);
        ord_publish($other, 'k', 'w', 10);
        ord_done($other);

        $expected = array_merge(
            array_map(static fn (int $i) => "v:$i:", range(0, 19)),
            array_map(static fn (int $i) => "w:$i:", range(0, 9)),
        );
        $received = 0;
        foreach ($subscribers as [$conn, $pid]) {
            if ($pid !== $victim) {
                expect(ord_events($conn, 30))->toBe($expected);
                ++$received;
            }
        }
        expect($received)->toBeGreaterThan(0);
    } finally {
        native_stop($process);
    }
});

test('after a reload, ordered subscribers of the new workers get what is published after they subscribed', function () {
    [$process, $addr, $log] = swerve_start([], 2);
    try {
        [$old] = ord_subscribe($addr, 'r', 1);
        swerve_signal($process, SIGHUP);
        log_wait($log, '/Reload complete/', 10);
        expect(ord_events($old, 1))->toBe([]); // its subscription ended with its worker's drain

        $subscribers = [];
        for ($i = 0; $i < 4; ++$i) {
            [$subscribers[]] = ord_subscribe($addr, 'r', 2);
        }
        expect(probe($addr, '/publish-ordered?topic=r&m=again'))->toBe('published');
        expect(probe($addr, '/publish-ordered?topic=r&m=and%20again'))->toBe('published');
        foreach ($subscribers as $conn) {
            expect(ord_events($conn, 2))->toBe(['again', 'and again']);
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('large messages from concurrent publishers arrive whole, in the same order for every subscriber', function () {
    [$process, $addr, $log] = swerve_start([], 3);
    try {
        $subscribers = [];
        for ($i = 0; $i < 4; ++$i) {
            [$subscribers[]] = ord_subscribe($addr, 'big', 40);
        }
        $sizes      = ['a' => 5000, 'b' => 30000, 'c' => 100000, 'd' => 4097];
        $publishers = [];
        foreach ($sizes as $id => $size) {
            [$conn] = ord_connect($addr);
            ord_publish($conn, 'big', $id, 10, "&size=$size&sleep=1");
            $publishers[] = $conn;
        }
        array_map('ord_done', $publishers);

        $sequence = null;
        foreach ($subscribers as $conn) {
            $events = ord_events($conn, 40);
            expect(count($events))->toBe(40);
            $sequence ??= $events;
            expect($events)->toBe($sequence);
        }
        $seen = array_fill_keys(array_keys($sizes), 0);
        foreach ($sequence as $message) {
            [$id, $i, $body] = explode(':', $message, 3);
            expect($i)->toBe((string) $seen[$id]++); // each publisher's order
            expect($body)->toBe(str_repeat((string) ($i % 10), $sizes[$id])); // whole
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('rotation: messages across many segments arrive in order and none is missing, old segments are unlinked, a stalled subscriber gets the lag exception', function () {
    $tmp                    = temp_path(true);
    [$process, $addr, $log] = swerve_start([], 3, env: ['SWERVE_TMPDIR' => $tmp, 'SWERVE_TEST_SEGMENT' => '0.5']);
    try {
        $subscribers = [];
        for ($i = 0; $i < 4; ++$i) {
            [$subscribers[]] = ord_subscribe($addr, 'rot', 120);
        }
        [$keep]    = ord_subscribe($addr, 'rot', 1000); // keeps a reader running, which sweeps
        [$stalled] = ord_subscribe($addr, 'rot', 1000, extra: '&maxlag=1&stall=2.5');
        [$a]       = ord_connect($addr);
        [$b]       = ord_connect($addr);
        ord_publish($a, 'rot', 'a', 60, '&sleep=50');
        ord_publish($b, 'rot', 'b', 60, '&sleep=50');

        $seen   = [];
        $live   = 0;
        $before = microtime(true);
        while (microtime(true) - $before < 3.0) {
            $files = array_keys(ord_files($tmp));
            $seen += array_flip($files);
            $live = max($live, count($files));
            usleep(20000);
        }
        array_map('ord_done', [$a, $b]);

        $sequence = null;
        foreach ($subscribers as $conn) {
            $events = ord_events($conn, 120);
            expect(count($events))->toBe(120);
            $sequence ??= $events;
            expect($events)->toBe($sequence);
        }
        expect(count($seen))->toBeGreaterThanOrEqual(5); // several segments
        expect($live)->toBeLessThanOrEqual(8);            // not all of them at once
        expect(ord_events($stalled, 1))->toBe(['lag']);
        $oldest = array_key_first($seen);
        ord_wait(static fn () => !array_key_exists($oldest, ord_files($tmp)), 6.0);
        expect($keep)->not->toBeNull();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a reader that was away longer than the retention has lost messages: its subscribers get the lag exception, the others lose nothing', function () {
    $tmp                    = temp_path(true);
    [$process, $addr, $log] = swerve_start(['--watchdog=0'], 3, env: ['SWERVE_TMPDIR' => $tmp, 'SWERVE_TEST_SEGMENT' => '0.5']);
    try {
        [$away, $awayPid] = ord_subscribe($addr, 'gap', 1000);
        do {
            [$other, $otherPid] = ord_subscribe($addr, 'gap', 50);
        } while ($otherPid === $awayPid);
        [$blocker]   = ord_connect($addr, $awayPid);
        [$publisher] = ord_connect($addr, null, [$awayPid]);

        fwrite($blocker, "GET /block?ms=3000 HTTP/1.1\r\nHost: t\r\n\r\n");
        usleep(100000);
        ord_publish($publisher, 'gap', 'g', 50, '&sleep=60');
        ord_done($publisher);
        expect(ord_events($other, 50))->toBe(array_map(static fn (int $i) => "g:$i:", range(0, 49)));
        expect(native_read_head($blocker)['status'])->toBe(200);
        $events = ord_events($away, 1000);
        expect(end($events))->toBe('lag');
    } finally {
        native_stop($process);
    }
});

test('SWERVE_TMPDIR is the base of the claims directory and the ordered logs, which the master removes at its exit', function () {
    $tmp                    = temp_path(true);
    [$process, $addr, $log] = swerve_start([], 2, env: ['SWERVE_TMPDIR' => $tmp]);
    try {
        foreach (['swerve-claims-*', 'swerve-ordered-*'] as $pattern) {
            $dirs = glob("$tmp/$pattern");
            expect($dirs)->toHaveCount(1);
            expect(fileperms($dirs[0]) & 0777)->toBe(0700);
        }
        [$conn] = ord_subscribe($addr, 'tmp', 1);
        expect(probe($addr, '/publish-ordered?topic=tmp&m=x'))->toBe('published');
        expect(ord_events($conn, 1))->toBe(['x']);
        expect(ord_files($tmp))->not->toBe([]);
    } finally {
        native_stop($process);
    }
    expect(glob("$tmp/*"))->toBe([]);
});

test('without a master, ordered subscribers receive in publishing order, apart from plain ones, and end with their coroutines', function () {
    $got = phasync::run(function () {
        $plain   = Swerve::subscribe('e');
        $readers = [];
        $got     = [];
        foreach (['plain' => $plain, 'a' => Swerve::subscribeOrdered('e'), 'b' => Swerve::subscribeOrdered('e')] as $name => $subscription) {
            $readers[] = phasync::go(static function () use ($subscription, $name, &$got) {
                $want = 'plain' === $name ? 2 : 5;
                foreach ($subscription as $message) {
                    $got[$name][] = $message;
                    if ($want === count($got[$name])) {
                        break;
                    }
                }
            });
        }
        unset($plain, $subscription);
        foreach (range(1, 5) as $i) {
            Swerve::publishOrdered('e', $i);
            if ($i <= 2) {
                Swerve::publish('e', "p$i");
            }
        }
        foreach ($readers as $reader) {
            phasync::await($reader);
        }
        ksort($got);

        return $got;
    });

    expect($got)->toBe(['a' => [1, 2, 3, 4, 5], 'b' => [1, 2, 3, 4, 5], 'plain' => ['p1', 'p2']]);
    expect(Topics::active())->toBe([]);
});

test('Swerve refuses topics outside 1 to 255 bytes, null, a message over 128 KiB, and topics starting with a NUL byte', function () {
    $refused = static function (callable $call): bool {
        try {
            phasync::run($call);
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    };
    expect($refused(fn () => Swerve::publishOrdered('', 'x')))->toBeTrue();
    expect($refused(fn () => Swerve::publishOrdered(str_repeat('t', 256), 'x')))->toBeTrue();
    expect($refused(fn () => Swerve::publishOrdered('t', null)))->toBeTrue();
    expect($refused(fn () => Swerve::publishOrdered('t', str_repeat('x', 1 << 17))))->toBeTrue();
    expect($refused(fn () => Swerve::subscribeOrdered('')))->toBeTrue();
    expect($refused(fn () => Swerve::subscribeOrdered(str_repeat('t', 256))))->toBeTrue();
    foreach (["\0t", "\0o" . str_repeat('a', 64)] as $topic) {
        expect($refused(fn () => Swerve::publish($topic, 'x')))->toBeTrue();
        expect($refused(fn () => Swerve::subscribe($topic)))->toBeTrue();
        expect($refused(fn () => Swerve::publishOrdered($topic, 'x')))->toBeTrue();
        expect($refused(fn () => Swerve::subscribeOrdered($topic)))->toBeTrue();
    }
    expect($refused(fn () => Swerve::publishOrdered(str_repeat('t', 255), 'x')))->toBeFalse(); // the longest topic
});
