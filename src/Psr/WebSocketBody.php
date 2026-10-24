<?php

namespace Swerve\Psr;

use phasync;
use phasync\ReadChannelInterface;
use Psr\Http\Message\StreamInterface;
use Swerve\WebSocket;

/**
 * The `101` response's body: the outbound WebSocket frames, as a PSR-7 stream a middleware may wrap
 * around (`withBody()`) to see every byte the client is sent.
 *
 * Nothing of phasync's, and no coroutine, is touched until the first `read()` while {@see Bridge}
 * sends a `101`: that is when the handler starts (its own coroutine, over a {@see WebSocketDuplex} on
 * an unbuffered channel and the request's body). Read anywhere else, as the body of a response whose
 * status middleware changed (a `403`, say), it is empty and the handler never starts. Building the
 * response itself ({@see WebSocketResponse::from()}) needs no coroutine. `close()` ends the channel from this side, so a handler mid-send sees its write fail.
 *
 * @internal built by {@see WebSocketResponse}
 */
final class WebSocketBody implements TunnelBody
{
    private bool $started                   = false;
    private bool $ended                     = false;
    private bool $closed                    = false;
    private string $buffer                  = '';
    private int $position                   = 0;
    private ?ReadChannelInterface $outbound = null;

    /** @var \Closure|null the bridge's: closes the connection itself */
    private ?\Closure $closeConnection = null;

    /** @param \Closure(WebSocket): void $handler */
    public function __construct(
        private readonly StreamInterface $inbound,
        private readonly \Closure $handler,
        private readonly ?string $subprotocol,
        private readonly int $maxMessage,
    ) {
    }

    public function onHandlerClose(\Closure $close): void
    {
        $this->closeConnection = $close;
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

    /** True once closed, or once the handler has ended and nothing is left buffered. */
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
        throw new \RuntimeException('A WebSocket response body is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('A WebSocket response body is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('A WebSocket response body is not writable: send through the WebSocket given to the handler');
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
                $this->ended = true; // not sent as a 101: there is no WebSocket to run

                return '';
            }
            $this->started = true;
            \phasync::channel($outbound, $write);
            $outbound->activate(); // the bridge (the creator) waits on it alone until the handler, started here, writes
            $this->outbound  = $outbound;
            $connection      = new WebSocketDuplex($this->inbound, $write, fn () => $this->closeConnection && ($this->closeConnection)());
            $handler         = $this->handler;
            $subprotocol     = $this->subprotocol;
            $maxMessage      = $this->maxMessage;
            \phasync::go(static function () use ($connection, $handler, $subprotocol, $maxMessage, $write): void {
                try {
                    WebSocket::run($connection, $handler, $subprotocol, $maxMessage);
                } finally {
                    $write->close();
                }
            });
        }
        // One channel value (a frame, or a ping) at a time: waiting here for $length bytes to
        // pile up would hold back whatever already arrived, and a WebSocket's whole point is
        // that a small message is not delayed behind a later, bigger one.
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
