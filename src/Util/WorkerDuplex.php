<?php

namespace Swerve\Util;

use phasync;
use phasync\IOException;
use phasync\Net\Duplex;

/**
 * A {@see Duplex} over a connection `phasync\ext\worker_next()` handed out: the worker lot's
 * parent already accepted it, so this is never a listener, only ever one live connection.
 *
 * The same as {@see \phasync\Net\StreamDuplex}, which it can't extend (its stream is private),
 * plus {@see self::park()}: hands the connection back to the lot instead of closing it, for
 * {@see \Swerve\Util\HttpWorker} to give an idle keep-alive connection to another lifecycle.
 *
 * @internal
 */
final class WorkerDuplex implements Duplex
{
    private bool $eof    = false;
    private bool $closed = false;

    /** A write is under way: the next waits, so writes don't interleave. */
    private bool $writing = false;

    private string $peer;
    private string $local;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        \stream_set_blocking($stream, false);
        \stream_set_read_buffer($stream, 0);
        $this->peer  = (string) @\stream_socket_get_name($stream, true);
        $this->local = (string) @\stream_socket_get_name($stream, false);
    }

    public function read(int $max = 65536, ?float $timeout = null): string
    {
        $deadline = null === $timeout ? null : \microtime(true) + $timeout;
        while (!$this->closed && !$this->eof) {
            $data = @\fread($this->stream, $max);
            if (false === $data) {
                break;
            }
            if ('' !== $data) {
                return $data;
            }
            if (\feof($this->stream)) {
                break;
            }
            try {
                phasync::readable($this->stream, null === $deadline ? \PHP_FLOAT_MAX : \max(0.0, $deadline - \microtime(true)));
            } catch (IOException $e) {
                if ($this->closed) {
                    break;
                }
                throw $e;
            }
        }
        $this->eof = true;

        return '';
    }

    public function write(string $bytes, ?float $timeout = null): void
    {
        $deadline  = null === $timeout ? null : \microtime(true) + $timeout;
        $remaining = static fn () => null === $deadline ? \PHP_FLOAT_MAX : \max(0.0, $deadline - \microtime(true));
        while ($this->writing) {
            phasync::awaitFlag($this, $remaining());
        }
        $this->writing = true;
        try {
            while ('' !== $bytes) {
                if ($this->closed) {
                    throw new IOException('The connection is closed');
                }
                $n = @\fwrite($this->stream, $bytes);
                if (false === $n) {
                    throw new IOException('The connection broke');
                }
                $bytes = \substr($bytes, $n);
                if ('' !== $bytes) {
                    phasync::writable($this->stream, $remaining());
                }
            }
        } finally {
            $this->writing = false;
            phasync::raiseFlag($this);
        }
    }

    public function eof(): bool
    {
        if (!$this->eof && !$this->closed) {
            $peek      = @\stream_socket_recvfrom($this->stream, 1, \STREAM_PEEK);
            $this->eof = false === $peek ? \feof($this->stream) : '' === $peek;
        }

        return $this->eof || $this->closed;
    }

    public function pending(): bool
    {
        if ($this->closed) {
            return false;
        }
        $peek = @\stream_socket_recvfrom($this->stream, 1, \STREAM_PEEK);

        return \is_string($peek) && '' !== $peek;
    }

    public function end(): void
    {
        if (!$this->closed) {
            @\stream_socket_shutdown($this->stream, \STREAM_SHUT_WR);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        @\fclose($this->stream);
        phasync::raiseFlag($this);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function peer(): string
    {
        return $this->peer;
    }

    public function local(): string
    {
        return $this->local;
    }

    /**
     * Hand the connection back to the worker lot instead of closing it: held there until
     * readable again, then dispatched to whichever lifecycle is next in line.
     *
     * @throws \LogicException when bytes the peer sent are unread (see pending()): serve them
     *                          instead of parking
     */
    public function park(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        \phasync\ext\worker_park($this->stream);
        phasync::raiseFlag($this);
    }
}
