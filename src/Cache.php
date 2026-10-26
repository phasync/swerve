<?php

namespace Swerve;

use Psr\SimpleCache\CacheInterface;

/**
 * The cache every worker shares, from Swerve::cache(): a PSR-16 cache held in APCu, the shared
 * memory segment swerve's workers inherit from the master they are forked from (see bin/swerve.php,
 * which restarts itself with `apc.enable_cli=1`). A get, set or delete is one APCu call: no trip
 * to another process.
 *
 * ```php
 * $cache = Swerve::cache();
 * $user  = $cache->get("user.$id") ?? load_user($id);
 * $cache->set("user.$id", $user, 60);
 * ```
 *
 * Values are PHP values: APCu serializes them (an object included) on its own. An entry's
 * contents last as long as the master: a rolling reload keeps them, a restart empties them.
 * APCu's eviction is not LRU: once its shared memory is full it expunges to make room, and what
 * it expunges is not chosen by age of use. Its size is `-d apc.shm_size=...` (bin/swerve.php
 * sets it; a bigger cache needs a bigger one).
 *
 * Every key lives under a `swerve:` prefix, so {@see Cache::clear()} empties swerve's entries,
 * not another application's sharing the same APCu segment.
 *
 * It needs APCu, enabled for the CLI: without it, {@see Swerve::cache()} and {@see Cache::instance()}
 * throw a `LogicException` -- swerve's master arranges this itself, so this is only seen when the
 * cache is used outside a swerve worker. A key is a non-empty string without `{}()/\@:`, else a
 * {@see CacheKeyException} is thrown.
 *
 * It implements every version of PSR-16 (psr/simple-cache 1, 2 and 3; the application's Composer
 * picks one): parameters are untyped, as 1.0 declares them, and checked here instead -- a key
 * that is no string throws a CacheKeyException, a TTL that is no int, DateInterval or null a
 * TypeError.
 *
 * @see Swerve::cache
 * @see Swerve\CacheKeyException
 *
 * @topic resource-management
 *
 * @example examples/Cache/1-shared-cache.php Set, get and expire values
 */
final class Cache implements CacheInterface
{
    /** Every key lives under this prefix in APCu, so clear() touches only swerve's entries. */
    private const PREFIX = 'swerve:';

    private static ?self $instance = null;

    private function __construct()
    {
    }

    /**
     * The cache of this process: the one {@see Swerve::cache()} returns.
     *
     * @throws \LogicException without APCu enabled for the CLI
     *
     * @see Swerve::cache
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            if (!\extension_loaded('apcu') || !\apcu_enabled()) {
                throw new \LogicException("Swerve::cache() needs APCu (pecl install apcu); swerve's master enables it for the CLI");
            }
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The value stored under `$key`, or `$default` when there is none or it has expired.
     *
     * ```php
     * $user = Swerve::cache()->get("user.$id") ?? load_user($id);
     * ```
     *
     * @throws CacheKeyException for an invalid key
     *
     * @see Cache::set
     * @param string $key
     */
    public function get($key, mixed $default = null): mixed
    {
        $value = \apcu_fetch(self::prefixed($key), $found);

        return $found ? $value : $default;
    }

    /**
     * The values of `$keys`, by key: `$default` for those missing or expired.
     *
     * ```php
     * ['a' => $a, 'b' => $b] = Swerve::cache()->getMultiple(['a', 'b']);
     * ```
     *
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     *
     * @throws CacheKeyException for an invalid key
     *
     * @see Cache::setMultiple
     */
    public function getMultiple($keys, mixed $default = null): array
    {
        $keys  = self::keys($keys);
        $found = $keys ? \apcu_fetch(\array_map(self::prefix(...), $keys)) : [];
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = \array_key_exists(self::PREFIX . $key, $found) ? $found[self::PREFIX . $key] : $default;
        }

        return $values;
    }

    /**
     * Whether a value is stored under `$key` and has not expired.
     *
     * @throws CacheKeyException for an invalid key
     * @param string $key
     *
     * @example examples/Cache.has/1-null-and-missing.php A stored null and a missing key
     */
    public function has($key): bool
    {
        return \apcu_exists(self::prefixed($key));
    }

    /**
     * Store `$value` under `$key` for every worker, for `$ttl` seconds or until APCu evicts it.
     *
     * ```php
     * Swerve::cache()->set("user.$id", $user, 60);
     * ```
     *
     * @param string                 $key
     * @param int|\DateInterval|null $ttl seconds, an interval, or null for no expiry; zero or less deletes the key
     *
     * @return bool what APCu reports
     *
     * @throws CacheKeyException for an invalid key
     *
     * @see Cache::get
     * @see Cache::delete
     */
    public function set($key, mixed $value, $ttl = null): bool
    {
        return $this->setMultiple([self::keys([$key])[0] => $value], $ttl);
    }

    /**
     * Store several values, by key, with one `$ttl`, as {@see Cache::set()} does.
     *
     * @param iterable<string, mixed> $values
     * @param int|\DateInterval|null  $ttl    seconds, an interval, or null for no expiry; zero or less deletes the keys
     *
     * @return bool what APCu reports
     *
     * @throws CacheKeyException for an invalid key
     *
     * @example examples/Cache.setMultiple/1-several-at-once.php Several values with one ttl
     */
    public function setMultiple($values, $ttl = null): bool
    {
        if (!\is_iterable($values)) {
            throw new CacheKeyException('setMultiple() takes an iterable of key => value, not ' . \get_debug_type($values));
        }
        if (null !== $ttl && !\is_int($ttl) && !$ttl instanceof \DateInterval) {
            throw new \TypeError('A cache TTL is seconds (int), a DateInterval or null, not ' . \get_debug_type($ttl));
        }
        $items = [];
        foreach ($values as $key => $value) {
            $items[self::keys([(string) $key])[0]] = $value;
        }
        if ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }
        if (null !== $ttl && $ttl <= 0) {
            return $this->deleteMultiple(\array_keys($items)); // expired at once, as PSR-16 says
        }
        $prefixed = [];
        foreach ($items as $key => $value) {
            $prefixed[self::PREFIX . $key] = $value;
        }

        return [] === \apcu_store($prefixed, null, $ttl ?? 0);
    }

    /**
     * Remove `$key` from the cache of every worker.
     *
     * @return bool what APCu reports: false also when there was nothing to remove
     *
     * @throws CacheKeyException for an invalid key
     * @param string $key
     *
     * @example examples/Cache.delete/1-forget.php Delete one key, or several
     */
    public function delete($key): bool
    {
        return \apcu_delete(self::prefixed($key));
    }

    /**
     * Remove several keys, as {@see Cache::delete()} does.
     *
     * @param iterable<string> $keys
     *
     * @return bool true only when every key was removed
     *
     * @throws CacheKeyException for an invalid key
     */
    public function deleteMultiple($keys): bool
    {
        return [] === \apcu_delete(\array_map(self::prefix(...), self::keys($keys)));
    }

    /**
     * Remove everything from the cache of every worker.
     *
     * @example examples/Cache.clear/1-empty.php Empty the cache
     */
    public function clear(): bool
    {
        return \apcu_delete(new \APCUIterator('/^' . \preg_quote(self::PREFIX, '/') . '/'));
    }

    /** `$key`, prefixed and validated. */
    private static function prefixed(mixed $key): string
    {
        return self::PREFIX . self::keys([$key])[0];
    }

    /** `$key`, prefixed. */
    private static function prefix(string $key): string
    {
        return self::PREFIX . $key;
    }

    /**
     * PSR-16 keys: strings of at least one character, without {}()/\@:
     *
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     */
    private static function keys($keys): array
    {
        if (!\is_iterable($keys)) {
            throw new CacheKeyException('Cache keys come as an iterable of strings, not ' . \get_debug_type($keys));
        }
        $valid = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || '' === $key || false !== \strpbrk($key, '{}()/\@:')) {
                throw new CacheKeyException('A cache key is a non-empty string without {}()/\@:, not ' . \var_export($key, true));
            }
            $valid[] = $key;
        }

        return $valid;
    }
}
