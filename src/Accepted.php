<?php

namespace Swerve;

use phasync;
use phasync\SelectableInterface;

/**
 * A request {@see Swerve::accept()} took on: ready once it is answered and finished. Awaiting it
 * throws what its handler threw.
 *
 * ```php
 * phasync::await(Swerve::accept('public/', 'public/index.php'));   // one request, then swerve.php ends
 * ```
 */
final class Accepted implements SelectableInterface
{
    private bool $done = false;

    /** What the request threw, for await() to throw. */
    private ?\Throwable $failure = null;

    /** Its handler took the connection, for as long as that is open (Swerve::upgrade()). */
    private bool $upgraded = false;

    /** Raised when it is done or upgraded: what a worker's drain waits for. */
    private ?object $settled = null;

    /** Raised when it is done: not $this, which the app may use as a flag of its own. */
    private readonly object $flag;

    /**
     * @param \Closure(ClientRequest): void $handler the application's, for a request that is not a file
     * @param ?StaticFiles                  $files   the files served without the application
     */
    public function __construct(public readonly \Closure $handler, public readonly ?StaticFiles $files = null)
    {
        $this->flag = new \stdClass();
    }

    public function isReady(): bool
    {
        return $this->done;
    }

    /**
     * Wait until the request is answered and finished.
     *
     * @throws \Throwable what the request's handler threw (its client got a 500, or the
     *                    connection was aborted, as always)
     */
    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        if (!$this->done) {
            phasync::awaitFlag($this->flag, $timeout);
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /** @internal the handler threw $e */
    public function fail(\Throwable $e): void
    {
        $this->failure = $e;
    }

    /** @internal the connection finished the request */
    public function done(): void
    {
        $this->done = true;
        phasync::raiseFlag($this->flag);
        null === $this->settled || phasync::raiseFlag($this->settled);
    }

    /** @internal its handler runs an upgrade, as long as the connection is open */
    public function upgraded(): void
    {
        $this->upgraded = true;
        null === $this->settled || phasync::raiseFlag($this->settled);
    }

    /**
     * @internal for a worker's drain: wait until the request is done, or runs an upgrade, which
     * the stop then closes
     */
    public function awaitSettled(): void
    {
        while (!$this->done && !$this->upgraded) {
            phasync::awaitFlag($this->settled ??= new \stdClass());
        }
    }
}
