<?php

/*
 * Swerve::claim(): one holder per name across the workers, decided by the master.
 */

use Swerve\Claim;
use Swerve\Swerve;

function claimed_by(string $addr, string $n): array
{
    return cache_call($addr, "/claimed?n=$n");
}


test('a name has one holder across the workers, and other names are independent', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        expect(cache_call($addr, '/claim?n=job&ttl=60')[1])->toBeTrue();
        $refused = [];
        for ($i = 0; $i < 200 && count($refused) < 2; ++$i) { // until both workers were refused: the holder too
            [$pid, $got]    = cache_call($addr, '/claim?n=job&ttl=60');
            $refused[$pid]  = true;
            expect($got)->toBeFalse();
        }
        expect(count($refused))->toBe(2);

        $independent = [];
        for ($i = 0; $i < 200 && count($independent) < 2; ++$i) { // each worker claims a name of its own
            [$pid, $got]  = cache_call($addr, "/claim?n=own$i&ttl=60");
            $independent[$pid] = $got;
            expect($got)->toBeTrue();
        }
        expect(count($independent))->toBe(2);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('the holder renews; a release lets another worker claim', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        [$holder] = cache_call($addr, '/claim?n=job&ttl=60');
        for ($i = 0; $i < 200; ++$i) { // the holder's renew goes to whichever worker answers: find the holder itself
            [$pid, $renewed] = cache_call($addr, '/renew?n=job');
            if ($pid === $holder) {
                expect($renewed)->toBeTrue();
                break;
            }
            expect($renewed)->toBeFalse(); // the other worker holds no claim of its own
        }
        expect($pid)->toBe($holder);

        $released = false;
        for ($i = 0; $i < 200 && !$released; ++$i) {
            [$pid, $released] = cache_call($addr, '/release?n=job');
            expect($pid === $holder)->toBe($released);
        }
        expect($released)->toBeTrue();

        expect(cache_call($addr, '/claim?n=job&ttl=60')[1])->toBeTrue(); // free again, whoever asks
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a claim expires after its TTL; its holder then cannot renew', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        expect(cache_call($addr, '/claim?n=brief&ttl=1')[1])->toBeTrue();
        expect(cache_call($addr, '/claim?n=brief&ttl=1')[1])->toBeFalse();
        expect(cache_call($addr, '/renew?n=brief')[1])->toBeTrue();
        usleep(1_100_000);
        expect(cache_call($addr, '/renew?n=brief')[1])->toBeFalse();
        expect(cache_call($addr, '/claim?n=brief&ttl=60')[1])->toBeTrue();
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a claim is freed at once when its worker drains, not after its TTL', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$old, $got] = cache_call($addr, '/claim?n=job&ttl=60');
        expect($got)->toBeTrue();
        swerve_signal($process, SIGHUP);
        log_wait($log, '/Reload complete/');
        [$pid, $got] = cache_call($addr, '/claim?n=job&ttl=60');
        expect([$pid !== $old, $got])->toBe([true, true]);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('claimed() tells whether a name is held, in every worker', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $seenBy = static function (bool $expected) use ($addr): void {
            $pids = [];
            for ($i = 0; $i < 200 && count($pids) < 2; ++$i) {
                [$pid, $held] = claimed_by($addr, 'watched');
                $pids[$pid]   = true;
                expect($held)->toBe($expected);
            }
            expect(count($pids))->toBe(2);
        };
        $seenBy(false);
        expect(cache_call($addr, '/claim?n=watched&ttl=60')[1])->toBeTrue();
        $seenBy(true);
        expect(cache_call($addr, '/claimed?n=watched')[1])->toBeTrue();
        // released: whichever worker is asked, the holder is the one that can release it
        for ($i = 0; $i < 200 && !cache_call($addr, '/release?n=watched')[1]; ++$i);
        $seenBy(false);

        expect(cache_call($addr, '/claim?n=brief&ttl=1')[1])->toBeTrue();
        $held2 = claimed_by($addr, 'brief')[1];
        expect($held2)->toBeTrue();
        usleep(1_100_000);
        expect(claimed_by($addr, 'brief')[1])->toBeFalse(); // expired
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('across workers, a claim with a timeout waits, then gives up', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        expect(cache_call($addr, '/claim?n=busy&ttl=60')[1])->toBeTrue();
        [, $got, $elapsed] = cache_call($addr, '/claim?n=busy&ttl=60&timeout=0.3'); // whichever worker: the holder is refused as well
        expect($got)->toBeFalse();
        expect($elapsed)->toBeGreaterThanOrEqual(0.3)->toBeLessThan(0.5);
        expect(cache_call($addr, '/claim?n=idle&ttl=60&timeout=0.3')[1])->toBeTrue(); // a free name does not wait
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('a worker killed with SIGKILL frees its claims once the master sees it gone', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$old, $got] = cache_call($addr, '/claim?n=job&ttl=60');
        expect($got)->toBeTrue();
        expect(cache_call($addr, '/claimed?n=job')[1])->toBeTrue();
        posix_kill($old, SIGKILL);
        $deadline = microtime(true) + 10;
        do {
            usleep(50_000);
            $answer = probe($addr, '/claimed?n=job');
            $pid    = null === $answer ? $old : json_decode($answer, true)[0];
        } while ($pid === $old && microtime(true) < $deadline);
        expect($pid)->not->toBe($old); // a new worker answers
        expect(cache_call($addr, '/claimed?n=job')[1])->toBeFalse();
        expect(cache_call($addr, '/claim?n=job&ttl=60')[1])->toBeTrue();
    } finally {
        native_stop($process);
    }
});

test('without a master claimed() and a claim with a timeout work in the process', function () {
    expect(Swerve::claimed('idle-name'))->toBeFalse();
    $claim = Swerve::claim('wait', 60);
    expect(Swerve::claimed('wait'))->toBeTrue();

    $waited = phasync::run(function () use ($claim) {
        phasync::go(function () use ($claim) {
            phasync::sleep(0.1);
            $claim->release();
        });
        $start = microtime(true);
        $got   = Swerve::claim('wait', 60, 1.0);

        return [$got, microtime(true) - $start];
    });
    expect($waited[0])->toBeInstanceOf(Claim::class);
    expect($waited[1])->toBeGreaterThanOrEqual(0.08)->toBeLessThan(1.0);
    expect($claim->renew())->toBeFalse(); // released, taken by the waiter

    $start = microtime(true);
    $none  = phasync::run(fn () => Swerve::claim('wait', 60, 0.2));
    $took  = microtime(true) - $start;
    expect($none)->toBeNull();
    expect($took)->toBeGreaterThanOrEqual(0.2)->toBeLessThan(0.4);
    expect(Swerve::claimed('wait'))->toBeTrue();
});

test('without a master a claim is the process\'s own; a stale holder cannot affect the new one', function () {
    $claim = Swerve::claim('local', 60);
    expect($claim)->toBeInstanceOf(Claim::class);
    expect(Swerve::claim('local', 60))->toBeNull();
    expect(Swerve::claim('elsewhere', 60))->toBeInstanceOf(Claim::class); // another name
    expect($claim->renew())->toBeTrue();
    $claim->release();
    expect($claim->renew())->toBeFalse();

    $second = Swerve::claim('local', 60);
    expect($second)->toBeInstanceOf(Claim::class);
    expect($claim->renew())->toBeFalse(); // the stale holder, with a token of its own
    $claim->release();                    // and it frees nothing
    expect(Swerve::claim('local', 60))->toBeNull();
    expect($second->renew())->toBeTrue();
    $second->release();
    expect(Swerve::claim('local', 60))->toBeInstanceOf(Claim::class);

    $short = Swerve::claim('short', 0.05);
    usleep(80_000);
    $taken = Swerve::claim('short', 60); // expired: taken over
    expect($taken)->toBeInstanceOf(Claim::class);
    expect($short->renew())->toBeFalse();
    $short->release();
    expect($taken->renew())->toBeTrue();

    foreach ([['', 1], ['x', 0], ['x', -1]] as [$name, $ttl]) {
        expect(fn () => Swerve::claim($name, $ttl))->toThrow(InvalidArgumentException::class);
    }
});
