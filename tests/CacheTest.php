<?php

/*
 * Swerve::cache(): one PSR-16 cache for every worker, held by the master.
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

test('a worker never keeps a value older than the last write: kept values and kept misses are forgotten', function () {
    [$process, $addr, $log] = swerve_start(workers: 2);
    try {
        $readAll = static function (string $key) use ($addr): array {
            $seen = [];
            for ($i = 0; $i < 30; ++$i) {
                [$pid, $value] = cache_call($addr, "/cache-get?k=$key");
                $seen[$pid][]  = $value;
            }
            expect(count($seen))->toBe(2); // both workers answered, each from its local layer after the first

            return array_values(array_unique(array_merge(...array_values($seen)), SORT_REGULAR));
        };
        expect($readAll('fresh'))->toBe(['missing']);  // a miss, kept by both workers
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
        usleep(1_100_000);
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

test('the cache lives in the master: a rolling reload keeps it', function () {
    [$process, $addr, $log] = swerve_start(workers: 1);
    try {
        [$old] = cache_call($addr, '/cache-set?k=kept&v=42');
        swerve_signal($process, SIGHUP);
        log_wait($log, '/Reload complete/');
        [$pid, $value] = cache_call($addr, '/cache-get?k=kept');
        expect([$pid !== $old, $value])->toBe([true, 42]);
    } finally {
        native_stop($process);
    }
});

test('without a master the cache is the process\'s own, with PSR-16 key checks', function () {
    $cache = Cache::instance();
    expect($cache->set('local', ['a' => 1]))->toBeTrue();
    expect([$cache->get('local'), $cache->has('local'), $cache->get('none', 'default')])->toBe([['a' => 1], true, 'default']);
    expect($cache->setMultiple(['x' => 1, 'y' => 2], new DateInterval('PT1M')))->toBeTrue();
    expect($cache->getMultiple(['x', 'y', 'z']))->toBe(['x' => 1, 'y' => 2, 'z' => null]);
    expect($cache->set('x', 3, 0))->toBeTrue(); // a TTL of 0 or less deletes
    expect($cache->has('x'))->toBeFalse();
    expect($cache->clear())->toBeTrue();
    expect($cache->has('y'))->toBeFalse();
    foreach (['', 'a:b', 'a/b', 'a{b}', 'a@b'] as $bad) {
        expect(fn () => $cache->get($bad))->toThrow(Swerve\CacheKeyException::class);
    }
    expect(new Swerve\CacheKeyException())->toBeInstanceOf(Psr\SimpleCache\InvalidArgumentException::class);
});
