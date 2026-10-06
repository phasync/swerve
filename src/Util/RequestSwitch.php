<?php

namespace Swerve\Util;

use Swerve\ClientRequest;
use Swerve\Swerve;

/**
 * The closures Swerve::onRequestSwitch() registered, and how SwitchAwareLoggingContext calls them.
 *
 * Nothing here runs until something is registered: {@see \Swerve\Http\HttpConnection} asks
 * {@see self::active()} and gives a request's own {@see LoggingContext} (lazily, through
 * {@see RequestContextFactory}, as always) instead of a {@see SwitchAwareLoggingContext} until
 * then, so phasync's event loop never even checks whether a request's context is
 * `phasync\Context\SwitchAwareInterface`. Once something is registered, every later request's
 * context is entered eagerly, as a SwitchAwareLoggingContext (not lazily: phasync's lazy-context
 * bookkeeping does not expect a resume() it calls while entering one to itself start a coroutine,
 * see {@see self::invoke()}), and phasync calls its resume()/suspend() around a switch between
 * coroutines of two different requests - never between a request's own coroutines (phasync::go()
 * inside it shares its context), and never for the event loop's own code: see
 * SwitchAwareInterface for exactly when.
 *
 * Each closure runs in a coroutine of its own (see {@see self::invoke()}), awaited at once: a
 * closure that returned without suspending (the expected case) is already done, so awaiting it
 * cannot itself wait. One that suspended instead (phasync::sleep(), an await, ...) is cancelled
 * and never runs further, and a LogicException is logged in its place. Nothing a closure does is
 * allowed to reach the event loop or the request: every failure, including that one, is logged
 * by {@see Swerve::log()} and otherwise ignored.
 *
 * @internal
 */
final class RequestSwitch
{
    /** @var list<\Closure(ClientRequest):void> in the order Swerve::onRequestSwitch() registered them */
    public static array $resumeListeners = [];

    /** @var list<\Closure(ClientRequest):void> in the order Swerve::onRequestSwitch() registered them */
    public static array $suspendListeners = [];

    private function __construct()
    {
    }

    /** Swerve::onRequestSwitch(): keep both closures, in registration order. */
    public static function listen(\Closure $resume, \Closure $suspend): void
    {
        self::$resumeListeners[]  = $resume;
        self::$suspendListeners[] = $suspend;
    }

    /** Whether anything is registered: RequestContextFactory uses this to pick the context class. */
    public static function active(): bool
    {
        return [] !== self::$resumeListeners;
    }

    /** A coroutine of $request is about to run, after a different request's ran: in registration order. */
    public static function resume(ClientRequest $request): void
    {
        foreach (self::$resumeListeners as $listener) {
            self::invoke($listener, $request);
        }
    }

    /** A coroutine of a different request is about to run, after $request's: in reverse registration order. */
    public static function suspend(ClientRequest $request): void
    {
        for ($i = \count(self::$suspendListeners) - 1; $i >= 0; --$i) {
            self::invoke(self::$suspendListeners[$i], $request);
        }
    }

    /**
     * Run $listener with $request in a coroutine of its own: calling it directly could let a
     * suspend attempt reach into the real coroutine the event loop is in the middle of
     * switching to or from, since phasync's wait functions key off the event loop's own
     * notion of "the running fiber", not a plain `Fiber::suspend()` - a bare `Fiber` around the
     * closure would isolate the suspend itself, but not that. `phasync::go()` makes the listener
     * a coroutine the event loop knows about, so a suspend is its, correctly, and nothing is
     * corrupted; if it terminated at once (the expected case), awaiting it cannot itself wait.
     */
    private static function invoke(\Closure $listener, ClientRequest $request): void
    {
        $fiber = \phasync::go($listener, [$request]);
        if (!$fiber->isTerminated()) {
            \phasync::cancel($fiber);
            self::fail(new \LogicException('A Swerve::onRequestSwitch() callback must not suspend'));

            return;
        }
        try {
            \phasync::await($fiber);
        } catch (\Throwable $e) {
            self::fail($e);
        }
    }

    /** Never thrown into the request: logged by Swerve's own logger instead. */
    private static function fail(\Throwable $e): void
    {
        Swerve::log()->error('A Swerve::onRequestSwitch() callback failed: {exception}', ['exception' => $e]);
    }
}
