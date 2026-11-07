<?php

namespace Swerve\Util;

use phasync;
use phasync\ShutdownException;
use phasync\TimeoutException;
use Swerve\Swerve;

/**
 * Tells the application that its server must now close its connections, see Swerve::onShutdown(): this
 * process's when it is a swerve worker, or, for a request a {@see \Swerve\Server} serves, that
 * server's ({@see Shutdown::current()}).
 *
 * The callbacks are kept in a WeakMap by the Scope that registered them (a request's, as the
 * connection gives each request its own; outside any request the root scope, which lasts as
 * long as the process), so that the callbacks of a request that ended are gone with it and never
 * run: nothing here keeps a request alive. phasync collects the cycles a scope ends up in half a
 * second after a coroutine ends, so what a callback holds is freed shortly after its request.
 * A callback runs in the scope that registered it.
 *
 * @internal
 */
final class Shutdown
{
    /** @var \WeakMap<\Scope, list<\Closure>>|null the callbacks by the scope that registered them */
    private ?\WeakMap $listeners = null;

    /** Raised at the shutdown, for awaitShutdown(). */
    private ?object $flag = null;

    /** The shutdown has begun: Swerve::draining(). */
    public bool $fired = false;

    /** This process's, as a swerve worker: fired by Topics::drain(). */
    private static ?self $process = null;

    /** @var \WeakMap<\Scope, self>|null the Server's of each request it serves */
    private static ?\WeakMap $serving = null;

    public static function process(): self
    {
        return self::$process ??= new self();
    }

    /** The current request's: its Server's, or else this process's. */
    public static function current(): self
    {
        return self::$serving[\Scope::getCurrent()] ?? self::process();
    }

    /** The current scope is a request of the Server that $shutdown belongs to. */
    public static function serving(self $shutdown): void
    {
        self::$serving                         ??= new \WeakMap();
        self::$serving[\Scope::getCurrent()]    = $shutdown;
    }

    /** Run $callback at the shutdown, or at once in a coroutine of its own when that has begun; in the current scope either way. */
    public function listen(\Closure $callback): void
    {
        if ($this->fired) {
            self::run($callback);

            return;
        }
        $listeners         = $this->listeners ??= new \WeakMap();
        $scope             = \Scope::getCurrent();
        $list              = $listeners[$scope] ?? [];
        $list[]            = $callback;
        $listeners[$scope] = $list;
    }

    /** Wait for the shutdown; false when $timeout seconds passed first. */
    public function await(?float $timeout): bool
    {
        if (!$this->fired) {
            try {
                phasync::awaitFlag($this->flag ??= new \stdClass(), $timeout ?? \PHP_FLOAT_MAX);
            } catch (TimeoutException) {
                return false;
            } catch (ShutdownException $e) {
                // The drain cancels the waiting coroutines as it fires: woken by that, this still
                // returns true (#109); the coroutine's next wait throws
                if (!$this->fired) {
                    throw $e;
                }
            }
        }

        return true;
    }

    /** The shutdown begins: wake the waiters and run the callbacks of the scopes still alive, each in its scope. */
    public function fire(): void
    {
        $this->fired     = true;
        $listeners       = $this->listeners;
        $this->listeners = null;
        if (null !== $this->flag) {
            phasync::raiseFlag($this->flag);
        }
        foreach ($listeners ?? [] as $scope => $callbacks) {
            foreach ($callbacks as $callback) {
                $scope->run(self::run(...), $callback);
            }
        }
    }

    /** In a coroutine of its own, in the current scope: a slow callback delays no other, and one that throws stops none. */
    private static function run(\Closure $callback): void
    {
        phasync::go(static function () use ($callback) {
            try {
                $callback();
            } catch (ShutdownException) {
                // The worker's stop reached a wait of the callback's: the end it expects
            } catch (\Throwable $e) {
                Swerve::log()->error('A Swerve::onShutdown() callback failed: {exception}', ['exception' => $e]);
            }
        });
    }
}
