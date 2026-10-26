<?php

namespace Swerve;

use phasync;
use phasync\SelectableInterface;

/**
 * A request {@see Swerve::accept()} took on: ready once it is answered and finished, or once its
 * connection went idle after serving only static files.
 *
 * ```php
 * phasync::await(Swerve::accept('public/', 'public/index.php'));   // one request, then swerve.php ends
 * ```
 */
final class Accepted implements SelectableInterface
{
    private bool $done = false;

    /**
     * @param \Closure(ClientRequest): void $handler the application's, for a request that is not a file
     * @param ?StaticFiles                  $files   the files served without the application
     */
    public function __construct(public readonly \Closure $handler, public readonly ?StaticFiles $files = null)
    {
    }

    public function isReady(): bool
    {
        return $this->done;
    }

    public function await(float $timeout = \PHP_FLOAT_MAX): void
    {
        if (!$this->done) {
            phasync::awaitFlag($this, $timeout);
        }
    }

    /** @internal the connection finished the request */
    public function done(): void
    {
        $this->done = true;
        phasync::raiseFlag($this);
    }
}
