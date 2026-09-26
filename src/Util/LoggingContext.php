<?php

namespace Swerve\Util;

use phasync\CancelledException;
use phasync\Context\ContextInterface;
use phasync\Context\ContextTrait;
use Psr\Log\LoggerInterface;

/**
 * The phasync context of a worker's coroutines, see bin/swerve.php.
 *
 * A coroutine that fails when nobody awaits it, such as a background task the application
 * started with phasync::go(), leaves its exception to its context. phasync's own context keeps
 * the first one silently, to throw from phasync::run() when that returns; a worker never
 * returns from it, it exits, so a failed background task would leave no trace. It is logged.
 *
 * While PHP shuts down (after an exit()), destroying the suspended coroutines cancels what they
 * waited for: those CancelledExceptions come from the exit, not from the application.
 */
final class LoggingContext implements ContextInterface
{
    use ContextTrait;

    private bool $exiting = false;

    public function __construct(private readonly LoggerInterface $logger)
    {
        \register_shutdown_function(function () { $this->exiting = true; });
    }

    public function setContextException(\Throwable $exception): void
    {
        if (!$this->exiting || !$exception instanceof CancelledException) {
            $this->logger->error('Unhandled exception in a coroutine: {exception}', ['exception' => $exception]);
        }
    }
}
