<?php

namespace Swerve\Util\Classic;

use phasync\IOException;
use phasync\TimeoutException;
use Swerve\ClientRequest;

/**
 * Sends a request's body to the request server in BODY frames, then the end-of-body frame, in a
 * coroutine of its own while the response comes back. On STOP (the script ended without reading
 * it) it sends the end at once: the rest of the body is the client connection's to discard.
 *
 * @internal
 */
final class BodyPump
{
    private const CHUNK = 65536;

    private ?\Fiber $fiber = null;

    /** Waiting for the client's bytes, not in the middle of a frame: a stop may interrupt it. */
    private bool $reading = false;

    private bool $stopped = false;

    public function __construct(private readonly ClientRequest $request, private readonly Backend $backend)
    {
    }

    public function start(): void
    {
        $this->fiber = \phasync::go($this->run(...));
    }

    /** STOP: send no more of the body. */
    public function stop(): void
    {
        if (!$this->stopped) {
            $this->stopped = true;
            if ($this->reading) {
                \phasync::throw($this->fiber, new PumpStop());
            }
        }
    }

    /** Wait until the end-of-body frame is sent: after the response, the body is not wanted. */
    public function finish(): void
    {
        $this->stop();
        \phasync::await($this->fiber);
    }

    private function run(): void
    {
        try {
            while (!$this->stopped) {
                $this->reading = true;
                try {
                    $data = $this->request->read(self::CHUNK);
                } finally {
                    $this->reading = false;
                }
                if ('' === $data) {
                    break;
                }
                $this->backend->send([[Backend::BODY, $data]]);
            }
        } catch (PumpStop|IOException|TimeoutException) {
            // Stopped, or the client is gone: the body ends here
        }
        $this->backend->send([[Backend::BODY, '']]);
    }
}

/**
 * Interrupts a {@see BodyPump} waiting for the client.
 *
 * @internal
 */
final class PumpStop extends \Exception
{
}
