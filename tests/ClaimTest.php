<?php

/*
 * Swerve::claim(): one holder per name across the workers: a file in the master's directory with its holder's pid in it,
 * cleared by the master when that worker is gone.
 */

use Swerve\Claim;
use Swerve\Swerve;

function available_by(string $addr, string $n): array
{
    return cache_call($addr, "/available?n=$n");
}

test('a name has one holder across the workers, and other names are independent', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        expect(cache_call($addr, '/claim?n=job')[1])->toBeTrue();
        $refused = [];
        for ($i = 0; $i < 200 && count($refused) < 2; ++$i) { // until both workers were refused: the holder too
            [$pid, $got]   = cache_call($addr, '/claim?n=job');
            $refused[$pid] = true;
            expect($got)->toBeFalse();
        }
        expect(count($refused))->toBe(2);

        $independent = [];
        for ($i = 0; $i < 200 && count($independent) < 2; ++$i) { // each worker claims a name of its own
            [$pid, $got]       = cache_call($addr, "/claim?n=own$i");
            $independent[$pid] = $got;
            expect($got)->toBeTrue();
        }
        expect(count($independent))->toBe(2);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('held() is true in the holding worker only; a release lets another worker claim', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        [$holder] = cache_call($addr, '/claim?n=job');
        for ($i = 0; $i < 200; ++$i) { // the question goes to whichever worker answers: find the holder itself
            [$pid, $held] = cache_call($addr, '/held?n=job');
            if ($pid === $holder) {
                expect($held)->toBeTrue();
                break;
            }
            expect($held)->toBeFalse(); // the other worker holds no handle of its own
        }
        expect($pid)->toBe($holder);

        $released = false;
        for ($i = 0; $i < 200 && !$released; ++$i) {
            [$pid, $released] = cache_call($addr, '/release?n=job');
            expect($pid === $holder)->toBe($released);
        }
        expect($released)->toBeTrue();

        expect(cache_call($addr, '/claim?n=job')[1])->toBeTrue(); // free again, whoever asks
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('available() tells whether a name is free, in every worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $seenBy = static function (bool $expected) use ($addr): void {
            $pids = [];
            for ($i = 0; $i < 200 && count($pids) < 2; ++$i) {
                [$pid, $available] = available_by($addr, 'watched');
                $pids[$pid]        = true;
                expect($available)->toBe($expected);
            }
            expect(count($pids))->toBe(2);
        };
        $seenBy(true);
        expect(cache_call($addr, '/claim?n=watched')[1])->toBeTrue();
        $seenBy(false);
        // released: whichever worker is asked, the holder is the one that can release it
        for ($i = 0; $i < 200 && !cache_call($addr, '/release?n=watched')[1]; ++$i);
        $seenBy(true);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('across workers, an acquire with a timeout waits, then gives up', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        expect(cache_call($addr, '/claim?n=busy')[1])->toBeTrue();
        [, $got, $elapsed] = cache_call($addr, '/claim?n=busy&timeout=0.3'); // whichever worker: the holder is refused as well
        expect($got)->toBeFalse();
        expect($elapsed)->toBeGreaterThanOrEqual(0.3)->toBeLessThan(0.5);
        expect(cache_call($addr, '/claim?n=idle&timeout=0.3')[1])->toBeTrue(); // a free name does not wait
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a dropped handle releases the name in the holding worker, so another claims at once', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        [$holder] = cache_call($addr, '/claim?n=job');
        for ($i = 0; $i < 200; ++$i) { // the drop must reach the holder
            [$pid] = cache_call($addr, '/drop?n=job');
            if ($pid === $holder) {
                break;
            }
        }
        expect($pid)->toBe($holder);
        [, $got, $elapsed] = cache_call($addr, '/claim?n=job&timeout=2');
        expect($got)->toBeTrue();
        expect($elapsed)->toBeLessThan(1.0);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a worker killed with SIGKILL frees its claims once the master sees it gone', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$old, $got] = cache_call($addr, '/claim?n=job');
        expect($got)->toBeTrue();
        expect(cache_call($addr, '/available?n=job')[1])->toBeFalse();
        posix_kill($old, SIGKILL);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $answer = probe($addr, '/available?n=job');
            $pid    = null === $answer ? $old : json_decode($answer, true)[0];
        } while ($pid === $old && microtime(true) < $deadline);
        expect($pid)->not->toBe($old); // a new worker answers
        expect(cache_call($addr, '/available?n=job')[1])->toBeTrue();
        expect(cache_call($addr, '/claim?n=job')[1])->toBeTrue();
    } finally {
        native_stop($process);
    }
});

test('a worker the watchdog kills for being stuck frees its claims', function () {
    [$process, $addr, $log] = swerve_start(['--watchdog=2'], workers: 1);
    try {
        [$old, $got] = cache_call($addr, '/claim?n=job');
        expect($got)->toBeTrue();
        $stuck = native_connect($addr); // a request that spins
        fwrite($stuck, "GET /spin HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        log_wait($log, '/died: signal 9 \(SIGKILL\).*killed: watchdog/', 6);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $answer = probe($addr, '/available?n=job');
            $pid    = null === $answer ? $old : json_decode($answer, true)[0];
        } while ($pid === $old && microtime(true) < $deadline);
        expect($pid)->not->toBe($old);
        expect(cache_call($addr, '/claim?n=job')[1])->toBeTrue();
    } finally {
        native_stop($process);
    }
});

test('without a master available() and an acquire with a timeout work in the process', function () {
    expect(Swerve::claim('idle-name')->available())->toBeTrue();
    $claim = Swerve::claim('wait');
    expect($claim->acquire())->toBe($claim);
    expect(Swerve::claim('wait')->available())->toBeFalse();

    $waited = phasync::run(function () use ($claim) {
        phasync::go(function () use ($claim) {
            phasync::sleep(0.1);
            $claim->release();
        });
        $other = Swerve::claim('wait');
        $start = microtime(true);
        $got   = $other->acquire(1.0);

        return [$got, microtime(true) - $start];
    });
    expect($waited[0])->toBeInstanceOf(Claim::class);
    expect($waited[1])->toBeGreaterThanOrEqual(0.08)->toBeLessThan(1.0);
    expect($claim->held())->toBeFalse(); // released, taken by the waiter

    $start = microtime(true);
    $none  = phasync::run(fn () => Swerve::claim('wait')->acquire(0.2));
    $took  = microtime(true) - $start;
    expect($none)->toBeNull();
    expect($took)->toBeGreaterThanOrEqual(0.2)->toBeLessThan(0.4);
    expect(Swerve::claim('wait')->available())->toBeFalse();
});

test('without a master a claim is the process\'s own; a stale handle cannot affect the new holder', function () {
    $claim = Swerve::claim('local');
    expect($claim)->toBeInstanceOf(Claim::class);
    expect($claim->acquire())->toBe($claim);
    expect(Swerve::claim('local')->acquire())->toBeNull();
    expect(Swerve::claim('elsewhere')->acquire())->toBeInstanceOf(Claim::class); // another name
    $claim->release();

    $second = Swerve::claim('local');
    expect($second->acquire())->toBe($second);
    expect($claim->held())->toBeFalse(); // the stale handle
    $claim->release();                   // and it frees nothing
    expect(Swerve::claim('local')->acquire())->toBeNull();
    expect($second->held())->toBeTrue();
    $second->release();
    expect(Swerve::claim('local')->available())->toBeTrue();

    expect(fn () => Swerve::claim(''))->toThrow(InvalidArgumentException::class);
});

test('held() is true for the holding handle only, and ends with a release', function () {
    $a = Swerve::claim('held-name');
    $b = Swerve::claim('held-name');
    expect($a->held())->toBeFalse(); // not acquired yet
    expect($a->acquire())->toBe($a);
    expect($a->held())->toBeTrue();
    expect($b->held())->toBeFalse();
    $a->release();
    expect($a->held())->toBeFalse();
});

test('acquiring twice on one handle returns it again', function () {
    $claim = Swerve::claim('twice');
    expect($claim->acquire())->toBe($claim);
    expect($claim->acquire())->toBe($claim); // not refused by itself
    expect($claim->held())->toBeTrue();
    expect(Swerve::claim('twice')->acquire())->toBeNull();
});

test('a handle going out of scope releases the name; one never acquired has no effect', function () {
    (function () {
        $claim = Swerve::claim('scoped');
        expect($claim->acquire())->toBe($claim);
        expect(Swerve::claim('scoped')->available())->toBeFalse();
    })();
    expect(Swerve::claim('scoped')->available())->toBeTrue();

    $holder = Swerve::claim('kept');
    expect($holder->acquire())->toBe($holder);
    (function () {
        $refused = Swerve::claim('kept');
        expect($refused->acquire())->toBeNull();
        Swerve::claim('kept'); // never acquired at all
    })();
    expect($holder->held())->toBeTrue(); // neither freed what the holder has
    $holder->release();
});
