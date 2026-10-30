<?php

namespace Swerve\Http;

use phasync;

/**
 * Spare coroutines, reused, to go on with a connection while its request's code runs on after
 * the response (tail work). A request runs in its connection's coroutine; before it starts,
 * outside the request, the connection reserves a spare. When the request's coroutine suspends
 * with its response ended and its code still running, the spare takes the connection (one
 * transfer); tail work that never suspends just finishes in the connection's coroutine, which
 * goes on itself, and the spare goes back unused.
 *
 * A spare is started outside requests only, so it belongs to none (sapi()'s rule: a coroutine
 * belongs to the request of the coroutine that starts it); a request only signals it.
 *
 * @internal
 */
final class RequestPool
{
    /** Idle coroutines kept for the next requests; one more ends after its job. */
    private const IDLE_MAX = 16;

    /** @var list<self> waiting, not reserved */
    private static array $idle = [];

    /** Coroutines started so far, for the tests. */
    public static int $started = 0;

    private ?\Closure $job = null;

    private function __construct()
    {
    }

    /**
     * An idle coroutine, or a new one, kept for one job: give() it, or release() it.
     *
     * @throws \LogicException inside a request
     */
    public static function reserve(): self
    {
        if ('swerve' === \php_sapi_name()) {
            throw new \LogicException('Swerve BUG: a request coroutine is started inside a request');
        }
        $spare = \array_pop(self::$idle);
        if (null === $spare) {
            ++self::$started;
            $spare = new self();
            phasync::service($spare->loop(...)); // until it waits for its job
        }

        return $spare;
    }

    /** Run $job in this coroutine once the event loop gets to it. $job handles its own failures. */
    public function give(\Closure $job): void
    {
        $this->job = $job;
        phasync::raiseFlag($this);
    }

    /** Not needed after all: back to the idle ones, or ended above IDLE_MAX. */
    public function release(): void
    {
        if (\count(self::$idle) < self::IDLE_MAX) {
            self::$idle[] = $this;
        } else {
            $this->give(static function (): void {});
        }
    }

    private function loop(): void
    {
        try {
            while (true) {
                while (null === $this->job) {
                    phasync::awaitFlag($this);
                }
                $job       = $this->job;
                $this->job = null;
                try {
                    $job();
                } catch (\Throwable) {
                    // a job handles its own failures: one that gets here was cancelled with its run()
                }
                unset($job);
                if (\count(self::$idle) >= self::IDLE_MAX) {
                    return;
                }
                self::$idle[] = $this;
            }
        } catch (\Throwable) {
            // the run() ends (services are cancelled), or the worker stops
            $i = \array_search($this, self::$idle, true);
            if (false !== $i) {
                \array_splice(self::$idle, $i, 1);
            }
        }
    }
}
