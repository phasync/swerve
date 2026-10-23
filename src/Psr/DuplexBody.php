<?php

namespace Swerve\Psr;

use phasync;
use phasync\ReadChannelInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The `101` response's body for a raw (non-WebSocket) upgrade, see {@see \Swerve\UpgradeResponse}:
 * the outbound bytes the handler writes, as a PSR-7 stream a middleware may wrap around
 * (`withBody()`) to see every byte the client is sent.
 *
 * Exactly {@see WebSocketBody}'s own mechanism (a {@see \Swerve\Psr\WebSocketDuplex} over the
 * request's body and an unbuffered channel), minus the WebSocket framing: the handler is called
 * directly with the `Duplex`, so it reads and writes raw bytes.
 *
 * @internal built by {@see \Swerve\UpgradeResponse}
 */
final class DuplexBody implements StreamInterface
{
    private bool $started                   = false;
    private bool $ended                     = false;
    private bool $closed                    = false;
    private string $buffer                  = '';
    private int $position                   = 0;
    private ?ReadChannelInterface $outbound = null;

    /** @param \Closure(\phasync\Net\Duplex): void $handler */
    public function __construct(
        private readonly StreamInterface $inbound,
        private readonly \Closure $handler,
    ) {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->outbound?->close();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null; // streamed, until the handler ends it
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || ($this->ended && '' === $this->buffer);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('An upgrade response body is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('An upgrade response body is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('An upgrade response body is not writable: send through the Duplex given to the handler');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /**
     * The next outbound bytes, at most $length; starts the handler on the very first call.
     */
    public function read($length): string
    {
        if ($this->closed) {
            return '';
        }
        if (!$this->started) {
            if (!Bridge::upgrading()) {
                $this->ended = true; // not sent as a 101: there is nothing to run

                return '';
            }
            $this->started = true;
            \phasync::channel($outbound, $write);
            $outbound->activate(); // the bridge (the creator) waits on it alone until the handler, started here, writes
            $this->outbound = $outbound;
            $connection     = new WebSocketDuplex($this->inbound, $write);
            $handler        = $this->handler;
            \phasync::go(static function () use ($connection, $handler, $write): void {
                try {
                    $handler($connection);
                } finally {
                    $write->close();
                }
            });
        }
        if ('' === $this->buffer && !$this->ended) {
            $eof   = false;
            $chunk = $this->outbound->read(\PHP_FLOAT_MAX, $eof);
            if ($eof) {
                $this->ended = true;
            } else {
                $this->buffer = $chunk;
            }
        }
        $bytes           = \substr($this->buffer, 0, $length);
        $this->buffer    = \substr($this->buffer, \strlen($bytes));
        $this->position += \strlen($bytes);

        return $bytes;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        return null === $key ? [] : null;
    }
}
