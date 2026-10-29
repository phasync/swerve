<?php

namespace Swerve\Util;

use phasync;

/**
 * One subscription's messages, delivered by {@see Topics}, waiting to be read. A message's value
 * is shared by every inbox it goes to (an array is copied only on write), so it is kept once
 * until the slowest subscriber has read it. No coroutine: delivering raises the inbox's flag.
 *
 * @internal
 */
final class Inbox
{
    /** @var \SplQueue<array{0: int, 1: int, 2: mixed, 3: ?string}> [sequence, published, value, serialized copy] */
    private \SplQueue $messages;

    private bool $closed = false;

    public function __construct()
    {
        $this->messages = new \SplQueue();
    }

    /** @param array{0: int, 1: int, 2: mixed, 3: ?string} $message */
    public function push(array $message): void
    {
        $this->messages->enqueue($message);
        phasync::raiseFlag($this);
    }

    /** No more messages: read() returns what is left, then says the end. */
    public function close(): void
    {
        $this->closed = true;
        phasync::raiseFlag($this);
    }

    /**
     * The next message, waiting up to $timeout for one; null with $eof once closed and empty.
     *
     * @return array{0: int, 1: int, 2: mixed, 3: ?string}|null
     *
     * @throws \phasync\TimeoutException when none came within $timeout
     */
    public function read(float $timeout, ?bool &$eof = null): ?array
    {
        while ($this->messages->isEmpty()) {
            if ($this->closed) {
                $eof = true;

                return null;
            }
            phasync::awaitFlag($this, $timeout);
        }
        $eof = false;

        return $this->messages->dequeue();
    }
}
