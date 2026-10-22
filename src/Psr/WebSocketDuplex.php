<?php

namespace Swerve\Psr;

use phasync\ChannelException;
use phasync\IOException;
use phasync\Net\Duplex;
use phasync\WriteChannelInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The connection {@see \Swerve\WebSocket::run()} reads and writes, for a handler started by
 * {@see WebSocketResponse::from()}: inbound frames are the request's body (so a middleware that
 * wrapped it is honoured), outbound frames go to the channel that feeds the response's body.
 *
 * @internal built by {@see WebSocketResponse}
 */
final class WebSocketDuplex implements Duplex
{
    public function __construct(
        private readonly StreamInterface $inbound,
        private readonly WriteChannelInterface $outbound,
    ) {
    }

    public function read(int $max = 65536, ?float $timeout = null): string
    {
        return $this->inbound->eof() ? '' : $this->inbound->read($max);
    }

    public function write(string $bytes, ?float $timeout = null): void
    {
        try {
            $this->outbound->write($bytes, $timeout ?? \PHP_FLOAT_MAX);
        } catch (ChannelException $e) {
            throw new IOException('The WebSocket response body was closed', 0, $e);
        }
        if ($this->outbound->isClosed()) {
            // A write pending when the body closed is let through (see Channel::write()'s
            // closed-while-waiting case); the caller still needs to see the connection is gone.
            throw new IOException('The WebSocket response body was closed');
        }
    }

    public function eof(): bool
    {
        return $this->inbound->eof();
    }

    public function pending(): bool
    {
        return !$this->inbound->eof();
    }

    /** Finish our side: no more frames will be written; the inbound side still reads. */
    public function end(): void
    {
        $this->outbound->close();
    }

    public function close(): void
    {
        $this->outbound->close();
    }

    public function isClosed(): bool
    {
        return $this->outbound->isClosed();
    }

    public function peer(): string
    {
        return '';
    }

    public function local(): string
    {
        return '';
    }
}
