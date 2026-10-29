<?php

namespace Swerve\Http;

use phasync;

/**
 * The coroutines that run application requests, reused: a request's code runs in one of them,
 * not in its connection's coroutine, so it may go on after its response (tail work) while the
 * connection serves its next request, and starting a coroutine costs nothing per request.
 *
 * A pooled coroutine is a service, started by a connection's coroutine, never by a request: it
 * belongs to no request, and each job it runs is a request of its own (sapi()'s rule: a
 * coroutine belongs to the request of the coroutine that starts it).
 *
 * A job that exit() unwinds takes its coroutine with it: it never comes back to the pool.
 *
 * @internal
 */
final class RequestPool
{
    /** Idle coroutines kept for the next requests; one more ends after its job. */
    private const IDLE_MAX = 16;

    /** @var list<self> waiting for a job */
    private static array $idle = [];

    /** Coroutines started so far, for the tests. */
    public static int $started = 0;

    private ?\Closure $job;

    private function __construct(\Closure $job)
    {
        $this->job = $job;
    }

    /**
     * Run $job in an idle pooled coroutine, or a new one: up to its first suspension when new,
     * else once the event loop gets to it. $job handles its own failures.
     *
     * @throws \LogicException inside a request
     */
    public static function run(\Closure $job): void
    {
        if ('swerve' === \php_sapi_name()) {
            throw new \LogicException('Swerve BUG: a request coroutine is started inside a request');
        }
        $worker = \array_pop(self::$idle);
        if (null === $worker) {
            ++self::$started;
            phasync::service((new self($job))->loop(...));

            return;
        }
        $worker->job = $job;
        phasync::raiseFlag($worker);
    }

    private function loop(): void
    {
        while (true) {
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
            try {
                while (null === $this->job) {
                    phasync::awaitFlag($this);
                }
            } catch (\Throwable) {
                // the run() ends (services are cancelled), or the worker stops
                $i = \array_search($this, self::$idle, true);
                if (false !== $i) {
                    \array_splice(self::$idle, $i, 1);
                }

                return;
            }
        }
    }
}
