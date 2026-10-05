<?php

namespace Swerve\Util;

use phasync;
use phasync\TimeoutException;
use Swerve\Swerve;

/**
 * Tells the application that this worker must now close its connections, see Swerve::onShutdown().
 *
 * The callbacks are kept in a WeakMap by the context of the coroutine that registered them
 * (a request's, as the connection gives each request its own), so that the callbacks of a request that
 * ended are gone with it and never run: nothing here keeps a request alive. phasync collects
 * the cycles a context ends up in half a second after a coroutine ends, so what a callback
 * holds is freed shortly after its request.
 *
 * @internal
 */
final class Shutdown
{
    /** @var \WeakMap<object, list<\Closure>>|null the callbacks by the context that registered them */
    private static ?\WeakMap $listeners = null;
    /** Raised at the shutdown, for awaitShutdown(). */
    private static ?object $flag = null;

    /** Run $callback at the shutdown, or at once in a coroutine of its own when that has begun. */
    public static function listen(\Closure $callback): void
    {
        if (Topics::$closed) {
            self::run($callback);

            return;
        }
        $listeners = self::$listeners ??= new \WeakMap();
        $context   = phasync::getContext();
        $list      = $listeners[$context] ?? [];
        $list[]    = $callback;
        $listeners[$context] = $list;
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

    /** The shutdown begins: wake the waiters and run the callbacks of the contexts still alive. */
    public static function fire(): void
    {
        $listeners       = self::$listeners;
        self::$listeners = null;
        if (null !== self::$flag) {
            phasync::raiseFlag(self::$flag);
        }
        foreach ($listeners ?? [] as $callbacks) {
            foreach ($callbacks as $callback) {
                self::run($callback);
            }
        }
    }

    /** In a coroutine of its own: a slow callback delays no other, and one that throws stops none. */
    private static function run(\Closure $callback): void
    {
        phasync::go(static function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Swerve::log()->error('A Swerve::onShutdown() callback failed: {exception}', ['exception' => $e]);
            }
        });
    }
}
