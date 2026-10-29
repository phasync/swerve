<?php

/*
 * In-process tests of Cache: the methods are called directly, no server is started. They need
 * APCu enabled for the CLI in this very process (apc.enable_cli=1), which the suite's own
 * invocation does not set: skipped otherwise, see beforeEach() below.
 */

use Swerve\Cache;

beforeEach(function () {
    if (!\extension_loaded('apcu') || !\apcu_enabled()) {
        test()->markTestSkipped('needs apc.enable_cli=1 in this process: run php with -d apc.enable_cli=1, as swerve\'s own master does for its workers');
    }
});

test('a stored value keeps its type: null, false, objects and nested arrays', function () {
    $cache = Cache::instance();
    $cache->clear();
    $object = new ArrayObject([1, 2]);
    foreach (['n' => null, 'f' => false, 'z' => 0, 'e' => '', 'a' => ['x' => ['y' => 1.5]], 'o' => $object] as $key => $value) {
        expect($cache->set("unit.$key", $value))->toBeTrue();
    }
    expect($cache->get('unit.n', 'default'))->toBeNull();   // a stored null is not a miss
    expect($cache->has('unit.n'))->toBeTrue();
    expect([$cache->get('unit.f'), $cache->get('unit.z'), $cache->get('unit.e')])->toBe([false, 0, '']);
    expect($cache->get('unit.a'))->toBe(['x' => ['y' => 1.5]]);
    expect($cache->get('unit.o'))->toEqual($object);
    $cache->clear();
});

test('delete and deleteMultiple remove keys; a key that was never there counts as removed', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    expect($cache->delete('a'))->toBeTrue();
    expect($cache->delete('never-set'))->toBeTrue();
    expect($cache->deleteMultiple(['b', 'missing']))->toBeTrue();
    expect($cache->getMultiple(['a', 'b', 'c'], 'gone'))->toBe(['a' => 'gone', 'b' => 'gone', 'c' => 3]);
    $cache->clear();
});

test('getMultiple and setMultiple take generators, and return the keys in the order asked', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple((static function () {
        yield 'k1' => 'one';
        yield 'k2' => 'two';
    })());
    $values = $cache->getMultiple((static fn () => yield from ['k2', 'k1', 'k3'])(), 0);
    expect($values)->toBe(['k2' => 'two', 'k1' => 'one', 'k3' => 0]);
    expect(array_keys($cache->getMultiple([])))->toBe([]);
    $cache->clear();
});

test('a numeric key given to setMultiple is stored as a string', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->setMultiple([10 => 'ten']);
    expect($cache->get('10'))->toBe('ten');
    $cache->clear();
});

test('every key of a multiple call is checked, and nothing is stored when one is bad', function () {
    $cache = Cache::instance();
    $cache->clear();
    expect(fn () => $cache->setMultiple(['ok' => 1, 'bad:key' => 2]))->toThrow(Swerve\CacheKeyException::class);
    expect($cache->has('ok'))->toBeFalse();
    foreach (['a(b', 'a)b', 'a}b', 'a\\b'] as $bad) {
        expect(fn () => $cache->has($bad))->toThrow(Swerve\CacheKeyException::class);
        expect(fn () => $cache->delete($bad))->toThrow(Swerve\CacheKeyException::class);
    }
    expect(fn () => $cache->getMultiple(['fine', '']))->toThrow(Swerve\CacheKeyException::class);
    expect(fn () => $cache->setMultiple([''  => 1]))->toThrow(Swerve\CacheKeyException::class);
    expect($cache->has('a.b-c_d'))->toBeFalse(); // dot, dash and underscore are valid
});

test('untyped as PSR-16 1.0 declares them, the methods still refuse what is no key, no iterable or no TTL', function () {
    $cache = Cache::instance();
    foreach ([42, null, ['a']] as $bad) {
        expect(fn () => $cache->get($bad))->toThrow(Swerve\CacheKeyException::class);
        expect(fn () => $cache->set($bad, 1))->toThrow(Swerve\CacheKeyException::class);
    }
    expect(fn () => $cache->getMultiple('a'))->toThrow(Swerve\CacheKeyException::class, 'Cache keys come as an iterable of strings, not string');
    expect(fn () => $cache->deleteMultiple(1))->toThrow(Swerve\CacheKeyException::class);
    expect(fn () => $cache->setMultiple('a'))->toThrow(Swerve\CacheKeyException::class, 'setMultiple() takes an iterable of key => value, not string');
    expect(fn () => $cache->set('k', 1, '60'))->toThrow(TypeError::class, 'A cache TTL is seconds (int), a DateInterval or null, not string');
    expect($cache->has('k'))->toBeFalse();
});

test('a TTL as an integer or a DateInterval expires the entry; a zero or negative one deletes it at once', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('keep', 1, 3600);
    $cache->set('keep2', 2, new DateInterval('PT1H'));
    expect($cache->getMultiple(['keep', 'keep2']))->toBe(['keep' => 1, 'keep2' => 2]);
    $cache->set('keep', 3, -5);
    expect($cache->has('keep'))->toBeFalse();
    $cache->set('keep2', 4, DateInterval::createFromDateString('-1 minute'));
    expect($cache->has('keep2'))->toBeFalse();
    $cache->setMultiple(['p' => 1, 'q' => 2]);
    $cache->setMultiple(['p' => 9, 'q' => 9], 0);
    expect($cache->getMultiple(['p', 'q']))->toBe(['p' => null, 'q' => null]);
    $cache->clear();
});

test('a TTL of one second expires, and an entry set again without one lives on', function () {
    $cache = Cache::instance();
    $cache->clear();
    $cache->set('short', 'v', 1);
    $cache->set('long', 'v', 60);
    $cache->set('replaced', 'v', 1);
    $cache->set('replaced', 'w'); // a new set replaces the TTL
    expect($cache->has('short'))->toBeTrue();
    usleep(2_100_000); // APCu's ttl is whole seconds on the wall clock: a 1 s ttl needs up to 2 s to be sure
    expect([$cache->has('short'), $cache->get('short', 'gone'), $cache->has('long'), $cache->get('replaced')])->toBe([false, 'gone', true, 'w']);
    $cache->clear();
});

test('Swerve::cache() is the one shared instance', function () {
    expect(Swerve\Swerve::cache())->toBe(Cache::instance())->toBeInstanceOf(Psr\SimpleCache\CacheInterface::class);
});

test('clear() empties the cache, and nothing else of swerve\'s in APCu: the pub/sub log and the container\'s counts stay (#100)', function () {
    apcu_store('swerve:log:head', 41);
    apcu_store('swerve:container:' . md5('x') . ':n', 3);
    Cache::instance()->set('k', 'v');
    expect(Cache::instance()->clear())->toBeTrue();
    expect(Cache::instance()->has('k'))->toBeFalse();
    expect([apcu_fetch('swerve:log:head'), apcu_fetch('swerve:container:' . md5('x') . ':n')])->toBe([41, 3]);
    apcu_delete(['swerve:log:head', 'swerve:container:' . md5('x') . ':n']);
});
