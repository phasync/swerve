<?php

namespace Swerve;

use phasync\ShutdownException;

/**
 * Thrown into the coroutines still running when a worker stops, once its requests have finished
 * (or its drain time is over): clean up at once, then let it end; the worker exits shortly after,
 * whether or not they have. A phasync ShutdownException, so a CancelledException: code that only
 * needs to stop catches nothing. `$reason` says why, for telling a client what happens next.
 */
final class WorkerStoppingException extends ShutdownException
{
    public function __construct(public readonly StopReason $reason)
    {
        parent::__construct("The worker is stopping ({$reason->name})");
    }
}
