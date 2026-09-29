<?php

namespace Swerve\Util;

use phasync\Context\ExceptionHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The phasync context of a worker's coroutines, and of each request's (see bin/swerve.php and
 * NativeHttpConnection).
 *
 * A coroutine that fails when nobody awaits it, such as a background task the application
 * started with phasync::go(), is logged here; the rest of the worker serves on. Without a
 * handler the failure would fail the worker's phasync::run(), which drops every coroutine.
 */
final class LoggingContext implements ExceptionHandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handleException(\Throwable $exception): void
    {
        $this->logger->error('Unhandled exception in a coroutine: {exception}', ['exception' => $exception]);
    }
}
