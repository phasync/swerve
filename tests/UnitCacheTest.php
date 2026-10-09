<?php

/*
 * In-process tests of Cache: the methods are called directly, no server is started.
 */

use phasync\Util\LruCache;
use Swerve\Cache;
use Swerve\Util\Topics;

// ---- Cache, without a master: the process's own

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

test('delete and deleteMultiple remove keys, also missing ones, and answer true', function () {
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
    usleep(1_050_000);
    expect([$cache->has('short'), $cache->get('short', 'gone'), $cache->has('long'), $cache->get('replaced')])->toBe([false, 'gone', true, 'w']);
    $cache->clear();
});

// ---- Cache, the protocol between a worker and the master

test('serve() carries out a request on the store and has the workers forget the keys written', function () {
    $store  = new LruCache(maxBytes: 1 << 20);
    $forgot = [];
    $forget = static function (?array $keys) use (&$forgot): void {
        $forgot[] = $keys;
    };
    $ask = static fn (array $call): array => (static function (string $reply) {
        return [substr($reply, 0, 4), unserialize(substr($reply, 4))];
    })(Cache::serve($store, pack('N', 7) . serialize($call), $forget));

    [$id, $stored] = $ask(['set', ['a' => serialize(1), 'b' => serialize(2)], null]);
    expect([unpack('N', $id)[1], $stored, $forgot])->toBe([7, true, [['a', 'b']]]);
    [, $found] = $ask(['get', ['a', 'c']]);
    expect(array_keys($found))->toBe(['a']);                        // a missing key is not in the reply
    expect(unserialize(substr($found['a'], 8)))->toBe(1);           // 8 bytes of expiry come first
    expect(unpack('q', $found['a'])[1])->toBe(0);                   // none
    expect(count($forgot))->toBe(1);                                // a read forgets nothing
    $ask(['delete', ['a']]);
    $ask(['clear']);
    expect($forgot)->toBe([['a', 'b'], ['a'], null]);
    expect($store->count())->toBe(0);
});

test('serve() expires an entry with a TTL on the monotonic clock, and refuses an unknown request', function () {
    $store = new LruCache(maxBytes: 1 << 20);
    $none  = static function (?array $keys): void {
    };
    Cache::serve($store, pack('N', 1) . serialize(['set', ['t' => serialize('v')], 0.15]), $none);
    $get = static fn () => unserialize(substr(Cache::serve($store, pack('N', 2) . serialize(['get', ['t']]), $none), 4));
    expect(array_keys($get()))->toBe(['t']);
    usleep(250_000);
    expect($get())->toBe([]);
    expect(fn () => Cache::serve($store, pack('N', 3) . serialize(['nonsense']), $none))->toThrow(UnexpectedValueException::class);
});

test('a worker keeps what it read in its local layer, and forget() drops it', function () {
    $cache = Cache::instance();
    $cache->clear();
    $calls = [];
    $store = new LruCache(maxBytes: 1 << 20);
    $store->set('w', pack('q', 0) . serialize('from master'));
    // A fake master: it serves each request at once, and the reply reaches the worker as the pipe's reader would call reply()
    Topics::$toMaster = static function (string $topic, string $message) use (&$calls, $store): void {
        $calls[] = unserialize(substr($message, 4))[0];
        $reply   = Cache::serve($store, $message, static function (?array $keys): void {
        });
        phasync::go(static fn () => Cache::reply($reply));
    };
    Cache::$listening = true;
    try {
        phasync::run(static function () use ($cache, &$calls) {
            expect($cache->get('w'))->toBe('from master');
            expect($cache->get('w'))->toBe('from master');
            expect($cache->get('absent', 'dflt'))->toBe('dflt');
            expect($cache->get('absent', 'dflt'))->toBe('dflt');   // a miss is kept too
            expect($calls)->toBe(['get', 'get']);                  // one trip per key, not per read

            Cache::forget(serialize(['absent']));
            expect($cache->get('absent', 'dflt'))->toBe('dflt');
            expect($calls)->toBe(['get', 'get', 'get']);

            expect($cache->set('w', 'new'))->toBeTrue();
            Cache::forget(serialize(['w']));
            expect($cache->get('w'))->toBe('new');
            Cache::forget(serialize(null));                        // everything
            expect($cache->get('w'))->toBe('new');
            expect(end($calls))->toBe('get');
        });
    } finally {
        Topics::$toMaster = null;
        Cache::$listening = false;
        $cache->clear();
    }
});

test('a worker that does not serve yet cannot use the cache through a master', function () {
    $cache            = Cache::instance();
    Topics::$toMaster = static function (): void {
    };
    try {
        expect(fn () => $cache->set('k', 1))->toThrow(LogicException::class);
    } finally {
        Topics::$toMaster = null;
    }
});

test('Swerve::cache() is the one shared instance', function () {
    expect(Swerve\Swerve::cache())->toBe(Cache::instance())->toBeInstanceOf(Psr\SimpleCache\CacheInterface::class);
});
