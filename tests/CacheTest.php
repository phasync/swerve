<?php

/*
 * Swerve::cache(): one PSR-16 cache for every worker, held in APCu, the shared memory every
 * worker inherits from the master it is forked from.
 */

use Swerve\Cache;

test('what one worker stores, every worker reads; a delete is seen by all', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        [$writer, $stored] = cache_call($addr, '/cache-set?k=greeting&v=' . urlencode('{"text":"hello"}'));
        expect($stored)->toBeTrue();
        $readers = [];
        for ($i = 0; $i < 200 && count($readers) < 2; ++$i) { // until the second worker is ready too
            [$pid, $value]  = cache_call($addr, '/cache-get?k=greeting');
            $readers[$pid]  = true;
            expect($value)->toBe(['text' => 'hello']);
        }
        expect(count($readers))->toBe(2); // both workers answered

        expect(cache_call($addr, '/cache-del?k=greeting')[1])->toBeTrue();
        for ($i = 0; $i < 6; ++$i) {
            expect(cache_call($addr, '/cache-get?k=greeting')[1])->toBe('missing');
        }
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('every worker sees a write or a delete at once: there is no per-worker copy to go stale', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $readAll = static function (string $key) use ($addr): array {
            $seen = [];
            for ($i = 0; $i < 30; ++$i) {
                [$pid, $value] = cache_call($addr, "/cache-get?k=$key");
                $seen[$pid][]  = $value;
            }
            expect(count($seen))->toBe(2); // both workers answered

            return array_values(array_unique(array_merge(...array_values($seen)), SORT_REGULAR));
        };
        expect($readAll('fresh'))->toBe(['missing']);  // not found by either worker
        cache_call($addr, '/cache-set?k=fresh&v=1');
        expect($readAll('fresh'))->toBe([1]);
        cache_call($addr, '/cache-set?k=fresh&v=2');
        expect($readAll('fresh'))->toBe([2]);
        cache_call($addr, '/cache-del?k=fresh');
        expect($readAll('fresh'))->toBe(['missing']);
    } finally {
        native_stop($process);
    }
    expect(log_count($log, '/(ERROR|CRITICAL)/'))->toBe(0, file_get_contents($log));
});

test('an entry with a TTL expires', function () {
    [$process, $addr] = swerve_start(workers: 1);
    try {
        cache_call($addr, '/cache-set?k=brief&v=1&ttl=1');
        expect(cache_call($addr, '/cache-get?k=brief')[1])->toBe(1);
        usleep(2_100_000); // APCu's ttl is whole seconds on the wall clock: a 1 s ttl needs up to 2 s to be sure
        expect(cache_call($addr, '/cache-get?k=brief')[1])->toBe('missing');
    } finally {
        native_stop($process);
    }
});

test('many lookups at once from coroutines of one request each get their own answer', function () {
    [$process, $addr] = swerve_start(workers: 1);
    try {
        expect(cache_call($addr, '/cache-many'))->toBe(range(1, 50));
    } finally {
        native_stop($process);
    }
});

test('a reload, which restarts swerve, starts the cache empty', function () {
    test()->markTestSkipped('0.2: SIGHUP reload is not ported yet');
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$old] = cache_call($addr, '/cache-set?k=kept&v=42');
        swerve_signal($process, SIGHUP);
        log_wait($log, '/Reload complete/', 8);
        [$pid, $value] = cache_call($addr, '/cache-get?k=kept');
        expect([$pid !== $old, $value])->toBe([true, 'missing']);
    } finally {
        native_stop($process);
    }
});

test('the cache works directly in this process too, with PSR-16 key checks', function () {
    $cache = Cache::instance();
    expect($cache->set('local', ['a' => 1]))->toBeTrue();
    expect([$cache->get('local'), $cache->has('local'), $cache->get('none', 'default')])->toBe([['a' => 1], true, 'default']);
    expect($cache->setMultiple(['x' => 1, 'y' => 2], new DateInterval('PT1M')))->toBeTrue();
    expect($cache->getMultiple(['x', 'y', 'z']))->toBe(['x' => 1, 'y' => 2, 'z' => null]);
    expect($cache->set('x', 3, 0))->toBeTrue(); // a TTL of 0 or less deletes; 'x' was there
    expect($cache->has('x'))->toBeFalse();
    expect($cache->clear())->toBeTrue();
    expect($cache->has('y'))->toBeFalse();
    foreach (['', 'a:b', 'a/b', 'a{b}', 'a@b'] as $bad) {
        expect(fn () => $cache->get($bad))->toThrow(Swerve\CacheKeyException::class);
    }
    expect(new Swerve\CacheKeyException())->toBeInstanceOf(Psr\SimpleCache\InvalidArgumentException::class);
})->skip(!\extension_loaded('apcu') || !\apcu_enabled(), 'needs apc.enable_cli=1 in this process: run php with -d apc.enable_cli=1, as swerve\'s own master does for its workers');
