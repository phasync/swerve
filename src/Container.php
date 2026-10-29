<?php

namespace Swerve;

use phasync;
use phasync\CancelledException;
use phasync\ShutdownException;
use phasync\TimeoutException;
use Psr\Container\ContainerInterface;
use Swerve\Util\Building;
use Swerve\Util\ContainerSlot;
use Swerve\Util\SharedLog;
use Swerve\Util\Topics;

/**
 * The container from {@see Swerve::container()}: a PSR-11 container for frameworks, whose purpose
 * is to limit how many instances of a service exist at once, across every worker.
 *
 * A framework keeps its own container and asks this one for the resources that need a limit,
 * typically the database connection its connector builds: MySQL's `max_connections` (151 by
 * default) is the server's, not a worker's.
 *
 * ```php
 * // swerve.php, before accept(): every worker adds the same services
 * Swerve::container()->add(\PDO::class, fn () => new \PDO($dsn, $user, $password), limit: 151);
 *
 * $db = Swerve::container()->get(\PDO::class); // waits while 151 are outstanding
 * ```
 *
 * An instance is outstanding until it is garbage: a {@see Lifetime::Scoped} one (the default)
 * when its request has ended and nothing else holds it, a {@see Lifetime::Transient} one when
 * the code that got it lets go. A request takes one slot however often it calls get(). Something
 * that keeps an instance for good (a static, a reference cycle the collector has not reached)
 * keeps its slot. A worker that dies gives its slots back.
 *
 * The counts live in APCu beside {@see Swerve::cache()}: when APCu runs out of memory and
 * expunges, they start over from zero, and until the outstanding instances are gone more than the
 * limit may be out.
 *
 * @topic resource-management
 */
final class Container implements ContainerInterface
{
    private const PREFIX = 'swerve:container:';

    /** Seconds a get() waiting for a slot waits before it looks again, should a release go unheard. */
    private const RETRY = 1.0;

    private static ?self $instance = null;

    /** @var array<string, array{0: \Closure(ContainerInterface): mixed, 1: int, 2: Lifetime, 3: string}> factory, limit, lifetime, key */
    private array $services = [];

    /** @var array<string, \WeakMap<object, object>> by id: the shared instance (or its Building) per Scope, or per container for a singleton */
    private array $shared = [];

    /** @var \WeakMap<object, ContainerSlot> the slot each limited instance holds */
    private \WeakMap $slots;

    /** @var array<string, int> by key: releases heard, so a get() that missed one tries again */
    private array $releases = [];

    /** @var array<string, \stdClass> by key: raised on a release */
    private array $flags = [];

    private function __construct()
    {
        $this->slots = new \WeakMap();
    }

    /** The container of this process. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Add a service: `$factory` builds its instances, `$limit` caps how many are outstanding
     * across every worker, and `$lifetime` says how long one is shared.
     *
     * Every worker adds its services (factories are closures, which can't be shared), each with
     * the same limit: swerve.php before accept() is the place.
     *
     * @param string                              $id       the name get() takes, by convention the class
     * @param callable(ContainerInterface): mixed $factory  builds an instance, which must be an object; it gets this container
     * @param int                                 $limit    instances outstanding at once, across all workers
     * @param Lifetime                            $lifetime how long an instance is shared
     *
     * @throws \LogicException when `$id` is added already, or a limit needs APCu and it is not enabled
     * @throws \ValueError     for a limit below 1
     */
    public function add(string $id, callable $factory, int $limit = \PHP_INT_MAX, Lifetime $lifetime = Lifetime::Scoped): void
    {
        if (isset($this->services[$id])) {
            throw new \LogicException("The service $id is added already");
        }
        if ($limit < 1) {
            throw new \ValueError("A limit is at least 1, not $limit");
        }
        if (\PHP_INT_MAX !== $limit && (!\extension_loaded('apcu') || !\apcu_enabled())) {
            throw new \LogicException("A limit needs APCu (pecl install apcu); swerve's master enables it for the CLI");
        }
        $this->services[$id] = [$factory(...), $limit, $lifetime, self::PREFIX . \md5($id)];
    }

    /** Whether `$id` was added. */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * The instance of `$id` for this request (Scoped), a new one (Transient), or this worker's
     * (Singleton); waits while the service's limit is reached.
     *
     * Two coroutines of one request that ask at once share the one instance: the second waits
     * for the first's factory.
     *
     * @throws ServiceNotFoundException when `$id` was not added
     * @throws ContainerException       when the factory throws, or returns no object
     */
    public function get(string $id): object
    {
        return $this->obtain($id, true);
    }

    /**
     * As get(), without waiting for a slot: null, and `$success` false, while the service's
     * limit is reached. The factory may still wait (connecting), and so may a coroutine whose
     * request has the instance being built already.
     *
     * ```php
     * $db = Swerve::container()->tryGet(\PDO::class, $success);
     * if (!$success) {
     *     http_response_code(503);   // busy: no connection free now
     * }
     * ```
     *
     * @param bool|null $success set to whether an instance was returned
     *
     * @throws ServiceNotFoundException when `$id` was not added
     * @throws ContainerException       when the factory throws, or returns no object
     */
    public function tryGet(string $id, ?bool &$success = null): mixed
    {
        $instance = $this->obtain($id, false);
        $success  = null !== $instance;

        return $instance;
    }

    /** get() with $wait, tryGet() without: null when a slot was not free. */
    private function obtain(string $id, bool $wait): ?object
    {
        [$factory, $limit, $lifetime, $key] = $this->services[$id] ?? throw new ServiceNotFoundException("No service $id: add() it first");
        if (Lifetime::Transient === $lifetime) {
            return $this->build($id, $factory, $limit, $key, $wait);
        }
        $owner  = Lifetime::Scoped === $lifetime ? \Scope::getCurrent() : $this;
        $shared = $this->shared[$id] ??= new \WeakMap();
        while (isset($shared[$owner])) {
            if (!$shared[$owner] instanceof Building) {
                return $shared[$owner];
            }
            phasync::awaitFlag($shared[$owner]); // another coroutine of the request builds it
        }
        $shared[$owner] = $building = new Building();
        try {
            $instance = $this->build($id, $factory, $limit, $key, $wait);
            if (null !== $instance) {
                $shared[$owner] = $instance;
            }

            return $instance;
        } finally {
            if ($shared[$owner] === $building) {
                unset($shared[$owner]); // it failed, or no slot was free: the next get() tries again
            }
            phasync::raiseFlag($building);
        }
    }

    /** Forget the slots of a worker that is gone (the master, as it reaps one). */
    public static function forget(int $pid): void
    {
        if (!\extension_loaded('apcu') || !\apcu_enabled()) {
            return;
        }
        foreach (new \APCUIterator('/^' . \preg_quote(self::PREFIX, '/') . "[0-9a-f]{32}:$pid\$/") as $entry) {
            \apcu_dec(\substr($entry['key'], 0, -\strlen(":$pid")) . ':n', $entry['value']);
            \apcu_delete($entry['key']);
        }
    }

    /**
     * A slot of the service at `$key` is back: every worker hears it, through pub/sub; in a
     * process alone (a test, a script), this one's waiters are woken here. Never waits: a
     * destructor calls it.
     *
     * @internal ContainerSlot
     */
    public static function released(string $key): void
    {
        if (SharedLog::shared()) {
            Topics::publish("\0$key", true);
        } elseif (isset(self::$instance->flags[$key])) {
            self::$instance->heard($key);
        }
    }

    private function heard(string $key): void
    {
        ++$this->releases[$key];
        phasync::raiseFlag($this->flags[$key]);
    }

    private function build(string $id, \Closure $factory, int $limit, string $key, bool $wait): ?object
    {
        $slot = null;
        if (\PHP_INT_MAX !== $limit && null === $slot = $this->reserve($key, $limit, $wait)) {
            return null;
        }
        try {
            $instance = $factory($this);
        } catch (CancelledException $e) {
            throw $e; // phasync's own: not the factory's failure
        } catch (\Throwable $e) {
            throw new ContainerException("The factory of $id failed: {$e->getMessage()}", 0, $e);
        }
        if (!\is_object($instance)) {
            throw new ContainerException("The factory of $id returned " . \get_debug_type($instance) . ', not an object');
        }
        if (null !== $slot) {
            $this->slots[$instance] = $slot; // released as the instance is garbage
        }

        return $instance;
    }

    /** Take a slot of the service at `$key`, waiting while all `$limit` are out; without $wait, null then. */
    private function reserve(string $key, int $limit, bool $wait): ?ContainerSlot
    {
        $this->listen($key);
        while (true) {
            $heard = $this->releases[$key];
            if (ContainerSlot::increment("$key:n") <= $limit) {
                return new ContainerSlot($key);
            }
            \apcu_dec("$key:n");
            if (!$wait) {
                return null;
            }
            if ($heard === $this->releases[$key]) {
                try {
                    phasync::awaitFlag($this->flags[$key], self::RETRY);
                } catch (TimeoutException) {
                }
            }
        }
    }

    /** Hear the releases of the service at `$key`, from every worker, until this one drains. */
    private function listen(string $key): void
    {
        if (isset($this->flags[$key])) {
            return;
        }
        $this->flags[$key]    = new \stdClass();
        $this->releases[$key] = 0;
        if (!SharedLog::shared()) {
            return; // in a process alone, released() wakes the waiters itself
        }
        $subscription = new Subscription("\0$key"); // subscribed now: no release after this goes unheard
        phasync::service(function () use ($key, $subscription) {
            while (!Topics::$closed) {
                try {
                    foreach ($subscription as $_) {
                        $this->heard($key);
                    }
                } catch (SubscriberLagException) {
                    $this->heard($key); // some were lost: look again
                    $subscription = new Subscription("\0$key");
                } catch (ShutdownException) {
                    return;
                }
            }
        });
    }
}
