<?php

namespace Swerve\Classic;

use phasync\Net\Duplex;
use phasync\Util\ProcessInterface;

/**
 * One `php -S` process of a {@see ClassicPool}: its backend port, a connection to it ready for
 * the next request (see {@see ClassicProxy}), and the idle bookkeeping the pool's sweep uses to
 * find a process idle too long.
 *
 * @internal
 */
final class ClassicProcess
{
    /** Whether the pool currently holds this process idle (not handed to a request). */
    public bool $idle = false;

    /** When this process last went idle (microtime); what the sweep compares against IDLE. */
    public float $releasedAt = 0.0;

    /**
     * A connection already dialed to this process, not yet used for a request: `php -S` accepts
     * it and then just waits, so opening it ahead of time (right after the process starts, and
     * right after it finishes a response) hides the connect from the next request's latency. Null
     * when none is ready yet; {@see ClassicProxy} then dials one itself.
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

    public function stop(): void
    {
        $this->conn?->close();
        $this->conn = null;
        $this->process->stop();
    }
}
