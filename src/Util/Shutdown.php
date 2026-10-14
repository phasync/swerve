<?php

namespace Swerve\Util;

use phasync;
use phasync\ShutdownException;
use phasync\TimeoutException;
use Swerve\Swerve;

/**
 * Tells the application that this worker must now close its connections, see Swerve::onShutdown().
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
    private static ?\WeakMap $listeners = null;
    /** Raised at the shutdown, for awaitShutdown(). */
    private static ?object $flag = null;

    /** Run $callback at the shutdown, or at once in a coroutine of its own when that has begun; in the current scope either way. */
    public static function listen(\Closure $callback): void
    {
        if (Topics::$closed) {
            self::run($callback);

            return;
        }
        $listeners         = self::$listeners ??= new \WeakMap();
        $scope             = \Scope::getCurrent();
        $list              = $listeners[$scope] ?? [];
        $list[]            = $callback;
        $listeners[$scope] = $list;
    }

    /** Wait for the shutdown; false when $timeout seconds passed first. */
    public static function await(?float $timeout): bool
    {
        if (!Topics::$closed) {
            try {
                phasync::awaitFlag(self::$flag ??= new \stdClass(), $timeout ?? \PHP_FLOAT_MAX);
            } catch (TimeoutException) {
                return false;
            }
        }

        return true;
    }

    /** The shutdown begins: wake the waiters and run the callbacks of the scopes still alive, each in its scope. */
    public static function fire(): void
    {
        $listeners       = self::$listeners;
        self::$listeners = null;
        if (null !== self::$flag) {
            phasync::raiseFlag(self::$flag);
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
