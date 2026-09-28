<?php

namespace Swerve;

use phasync;
use phasync\Util\LruCache;
use Psr\SimpleCache\CacheInterface;
use Swerve\Util\Topics;

/**
 * The cache every worker shares, from Swerve::cache(): a PSR-16 cache held by the master
 * process, the least recently used entries evicted past --cache-size.
 *
 *     $cache = Swerve::cache();
 *     $user  = $cache->get("user:$id") ?? load_user($id);
 *     $cache->set("user:$id", $user, 60);
 *
 * Two layers. Each worker keeps what it read (and what it found missing) in a local layer of
 * LOCAL_BYTES, so a repeated read costs no trip to the master. Writes go to the master, which
 * then tells every worker, the writer included, to forget those keys; it sends no values, a
 * worker fetches a value when it next reads it. The master's messages to a worker arrive in the
 * order it sent them, and a worker stores what it fetched as it reads the reply, so a worker
 * never keeps a value older than the last write it was told of. A trip to the master costs
 * about 0.1 ms, during which only the calling coroutine waits.
 *
 * Values are serialized in the worker; the master keeps the strings and never unserializes
 * them, so application classes never load there. An entry with a TTL expires at the same
 * moment in every layer (the monotonic clock is the machine's). The contents last as long as
 * the master: a rolling reload keeps them, a restart empties them. Without a master (the
 * application served by something other than swerve's workers), the cache is the process's own.
 */
final class Cache implements CacheInterface
{
    /** Internal topics: requests and replies between a worker and the master; keys to forget. */
    public const TOPIC  = "\0cache";
    public const FORGET = "\0cache-forget";

    /** A worker's local layer. */
    public const LOCAL_BYTES = 8 << 20;

    private static ?self $instance = null;

    /** Set once the worker reads its pipe: before that no reply could arrive. */
    public static bool $listening = false;

    private int $next = 0;

    /** @var array<int, \stdClass> callers waiting for a reply, by request id */
    private array $waiting = [];

    /**
     * In a worker, the local layer; without a master, the whole cache. Entries are the master's
     * strings (see apply()), and '' for a key known to be missing.
     */
    private LruCache $layer;

    private function __construct()
    {
        $this->layer = new LruCache(maxBytes: null === Topics::$toMaster ? 64 << 20 : self::LOCAL_BYTES);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getMultiple([$key], $default)[$key];
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $values = [];
        foreach ($this->fetch(self::keys($keys)) as $key => $entry) {
            $values[$key] = '' === $entry ? $default : \unserialize(\substr($entry, 8));
        }

        return $values;
    }

    public function has(string $key): bool
    {
        return '' !== $this->fetch(self::keys([$key]))[$key];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->setMultiple([$key => $value], $ttl);
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $items = [];
        foreach ($values as $key => $value) {
            $items[self::keys([(string) $key])[0]] = \serialize($value);
        }
        if ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
        }
        if (null !== $ttl && $ttl <= 0) {
            return $this->deleteMultiple(\array_keys($items)); // expired at once, as PSR-16 says
        }

        return $this->call(['set', $items, $ttl]);
    }

    public function delete(string $key): bool
    {
        return $this->deleteMultiple([$key]);
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return $this->call(['delete', self::keys($keys)]);
    }

    public function clear(): bool
    {
        return $this->call(['clear']);
    }

    /**
     * In the master: carry out a worker's request on $store and return the reply's message.
     * A write first has $forget tell every worker which keys to forget (all of them: null), so
     * that the writer, too, hears of it before its reply.
     *
     * @param \Closure(?list<string> $keys): void $forget
     */
    public static function serve(LruCache $store, string $message, \Closure $forget): string
    {
        $call = \unserialize(\substr($message, 4), ['allowed_classes' => false]);
        if ('set' === $call[0] || 'delete' === $call[0]) {
            $forget(\array_map('strval', 'set' === $call[0] ? \array_keys($call[1]) : $call[1]));
        } elseif ('clear' === $call[0]) {
            $forget(null);
        }

        return \substr($message, 0, 4) . \serialize(self::apply($store, $call));
    }

    /**
     * In a worker: forget keys, as the master says after a write; null forgets everything.
     */
    public static function forget(string $message): void
    {
        if (null === self::$instance) {
            return; // nothing read yet, nothing to forget
        }
        $keys = \unserialize($message, ['allowed_classes' => false]);
        if (null === $keys) {
            self::$instance->layer->clear();

            return;
        }
        foreach ($keys as $key) {
            self::$instance->layer->delete($key);
        }
    }

    /**
     * In a worker: a reply from the master. What it fetched is stored in the local layer now,
     * in the order of the master's messages, then the caller waiting for it wakes.
     */
    public static function reply(string $message): void
    {
        $self   = self::$instance;
        $id     = \unpack('N', $message)[1];
        $waiter = $self->waiting[$id];
        unset($self->waiting[$id]);
        $waiter->result = \unserialize(\substr($message, 4), ['allowed_classes' => false]);
        foreach ($waiter->fetching ?? [] as $key) {
            $entry = $waiter->result[$key] ?? '';
            $ttl   = '' === $entry ? null : self::remaining($entry);
            if (null === $ttl || $ttl > 0) {
                $self->layer->set($key, $entry, $ttl);
            }
        }
        phasync::raiseFlag($waiter);
    }

    /**
     * The entries for $keys: from this process's layer, and what it lacks from the master.
     *
     * @param list<string> $keys
     *
     * @return array<string, string> '' for a missing key
     */
    private function fetch(array $keys): array
    {
        $entries = [];
        $lacking = [];
        foreach ($keys as $key) {
            $entry = $this->layer->get($key);
            if (null === $entry) {
                $lacking[] = $key;
            } else {
                $entries[$key] = $entry;
            }
        }
        if ($lacking && null !== Topics::$toMaster) {
            $found = $this->call(['get', $lacking], $lacking);
            foreach ($lacking as $key) {
                $entries[$key] = $found[$key] ?? '';
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $entries[$key] ?? '';
        }

        return $ordered;
    }

    /**
     * Carry out a request: on this process's layer without a master, else by the master.
     *
     * @param list<string>|null $fetching keys a get fetches, for the local layer
     */
    private function call(array $call, ?array $fetching = null): mixed
    {
        if (null === Topics::$toMaster) {
            return self::apply($this->layer, $call);
        }
        if (!self::$listening) {
            throw new \LogicException('Swerve::cache() is there once the worker serves, not while swerve.php loads');
        }
        $id                 = ++$this->next;
        $waiter             = new \stdClass();
        $waiter->fetching   = $fetching;
        $this->waiting[$id] = $waiter;
        (Topics::$toMaster)(self::TOPIC, \pack('N', $id) . \serialize($call));
        while (!\property_exists($waiter, 'result')) {
            phasync::awaitFlag($waiter);
        }

        return $waiter->result;
    }

    /**
     * A request on $store. Entries are the value serialized, after 8 bytes of its expiry on the
     * monotonic clock in nanoseconds (0: none).
     */
    private static function apply(LruCache $store, array $call): mixed
    {
        switch ($call[0]) {
            case 'get':
                $found = [];
                foreach ($call[1] as $key) {
                    $entry = $store->get((string) $key);
                    if (null !== $entry && '' !== $entry) {
                        $found[$key] = $entry;
                    }
                }

                return $found;
            case 'set':
                $expires = null === $call[2] ? 0 : \hrtime(true) + $call[2] * 1_000_000_000;
                $stored  = true;
                foreach ($call[1] as $key => $value) {
                    $stored = $store->set((string) $key, \pack('q', $expires) . $value, $call[2]) && $stored;
                }

                return $stored;
            case 'delete':
                foreach ($call[1] as $key) {
                    $store->delete((string) $key);
                }

                return true;
            case 'clear':
                $store->clear();

                return true;
        }
        throw new \UnexpectedValueException("Unknown cache request '$call[0]'");
    }

    /** Seconds until an entry expires; null for never. */
    private static function remaining(string $entry): ?float
    {
        $expires = \unpack('q', $entry)[1];

        return 0 === $expires ? null : ($expires - \hrtime(true)) / 1e9;
    }

    /**
     * PSR-16 keys: strings of at least one character, without {}()/\@:
     *
     * @param iterable<mixed> $keys
     *
     * @return list<string>
     */
    private static function keys(iterable $keys): array
    {
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
