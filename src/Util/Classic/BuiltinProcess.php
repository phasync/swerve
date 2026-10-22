<?php

namespace Swerve\Util\Classic;

use phasync\Net\Duplex;
use phasync\Util\ProcessInterface;

/**
 * One `php -S` process of a {@see BuiltinRunner}'s pool: its backend port, a connection to it
 * ready for the next request, and the idle timestamp the sweep compares against
 * {@see BuiltinRunner::IDLE}.
 *
 * @internal
 */
final class BuiltinProcess
{
    /** When this process last went idle (microtime); set by {@see BuiltinRunner::release()}. */
    public float $idleSince = 0.0;

    /**
     * A connection already dialed to this process, not yet used for a request: `php -S` accepts
     * it and then just waits, so opening it ahead of time (right after the process starts, and
     * right after it finishes a response) hides the connect from the next request's latency. Null
     * when none is ready yet; {@see BuiltinRunner} then dials one itself.
     */
    public ?Duplex $conn = null;

    public function __construct(
        public readonly ProcessInterface $process,
        public readonly int $port,
        ?Duplex $conn = null,
    ) {
        $this->conn = $conn;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /** Closes the preconnected connection, if any, and stops the process. */
    public function stop(): void
    {
        $this->conn?->close();
        $this->conn = null;
        $this->process->stop();
    }
}
