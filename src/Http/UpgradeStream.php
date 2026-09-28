<?php

namespace Swerve\Http;

use phasync;
use Psr\Http\Message\StreamInterface;

/**
 * The response body of a ProtocolUpgrade: what the connection sends, read by swerve as it is
 * written. swerve's read() waits for as long as the connection is quiet; only the writer's
 * wait for a client that stopped reading has a timeout.
 *
 * @internal
 */
final class UpgradeStream implements StreamInterface
{
    /** Bytes written and not yet read; a write waits while there are more. */
    private const BUFFER_SIZE = 65536;

    private string $buffer = '';
    private bool $ended    = false;

    /** Raised when bytes were written or the stream ended, and when bytes were read. */
    private object $written;
    private object $read;

    public function __construct()
    {
        $this->written = new \stdClass();
        $this->read    = new \stdClass();
    }

    /**
     * Add bytes, waiting up to $timeout seconds while the buffer is full.
     *
     * @throws phasync\TimeoutException when the client stopped reading
     */
    public function append(string $bytes, float $timeout): void
    {
        $this->buffer .= $bytes;
        phasync::raiseFlag($this->written);
        $deadline = \microtime(true) + $timeout;
        while (\strlen($this->buffer) > self::BUFFER_SIZE && !$this->ended) {
            phasync::awaitFlag($this->read, $deadline - \microtime(true));
        }
    }

    /** No more bytes: swerve closes the connection once the buffer is sent. */
    public function end(): void
    {
        $this->ended = true;
        phasync::raiseFlag($this->written);
        phasync::raiseFlag($this->read);
    }

    public function read(int $length): string
    {
        while ('' === $this->buffer && !$this->ended) {
            phasync::awaitFlag($this->written);
        }
        $bytes        = \substr($this->buffer, 0, $length);
        $this->buffer = \substr($this->buffer, \strlen($bytes));
        phasync::raiseFlag($this->read);

        return $bytes;
    }

    public function eof(): bool
    {
        return $this->ended && '' === $this->buffer;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new \RuntimeException('An upgraded connection has no position');
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('An upgraded connection is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('An upgraded connection is not seekable');
    }

    public function write($string): int
    {
        throw new \RuntimeException('Write through the ProtocolUpgrade');
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(self::BUFFER_SIZE);
        }

        return $contents;
    }

    public function close(): void
    {
        $this->buffer = '';
        $this->end();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getMetadata($key = null)
    {
        return null === $key ? [] : null;
    }

    public function __toString(): string
    {
        return '';
    }
}
