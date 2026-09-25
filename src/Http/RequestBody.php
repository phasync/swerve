<?php

namespace Swerve\Http;

use phasync\IOException;
use Psr\Http\Message\StreamInterface;

/**
 * The body of a request in HTTP mode, read straight from the connection as the application
 * reads it: a Content-Length body, or a chunked one decoded on the fly. Nothing is read ahead:
 * bytes already in the connection's buffer are used first, then the socket is read for at
 * most what the body has left (up to 64 KiB, however little the application asks for).
 *
 * A client that goes away mid-body makes read() throw a RuntimeException, as PSR-7 says.
 *
 * With `Expect: 100-continue`, the first read() sends `100 Continue`, so a client waiting
 * for it only sends the body when the application actually wants it.
 *
 * A malformed chunked body throws HttpError (400) from read(), as does a chunk that makes
 * the body larger than $maxSize (413), and every later read throws it again; the connection answers with its status and closes, even when the application
 * caught it.
 */
final class RequestBody implements StreamInterface
{
    /** Content-Length bytes left, or bytes left of the current chunk. */
    private int $remaining;

    /** A chunk's data is used up; its CRLF comes next. */
    private bool $chunkEnd = false;

    private bool $done;
    private int $position = 0;

    /** The request is over: its body may no longer be read. */
    private bool $finished = false;

    /** The body was malformed; sticky, so the connection sees it even if the app caught it. */
    public ?HttpError $error = null;

    /**
     * @param ?int $length  the Content-Length, or null for a chunked body
     * @param bool $continue send `100 Continue` on the first read
     * @param int  $maxSize  the largest chunked body; a Content-Length is checked before
     */
    public function __construct(
        private readonly NativeHttpConnection $connection,
        private readonly ?int $length,
        private bool $continue,
        private readonly int $maxSize,
    ) {
        $this->remaining = $length ?? 0;
        $this->done      = 0 === $length;
    }

    /**
     * Returns '' only when the body has ended.
     */
    public function read(int $length): string
    {
        if ($this->finished) {
            throw new \LogicException('The request is over; its body can no longer be read');
        }
        if (null !== $this->error) {
            throw $this->error;
        }
        if ($this->done || $length <= 0) {
            return '';
        }
        try {
            if ($this->continue) {
                $this->continue = false;
                $this->connection->sendContinue();
            }
            if (null === $this->length && 0 === $this->remaining) {
                $this->nextChunk();
                if ($this->done) {
                    return '';
                }
            }
            // What follows a chunk's data is still this body (its CRLF, the next chunk-size
            // line), so a socket read may take more than the chunk has left
            $bytes = $this->connection->readBody(\min($length, $this->remaining), null === $this->length ? \PHP_INT_MAX : $this->remaining);
        } catch (HttpError $e) {
            throw $this->error = $e;
        } catch (IOException $e) {
            // Not a RuntimeException, the only exception PSR-7 lets read() throw (a timeout is one)
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $n                = \strlen($bytes);
        $this->remaining -= $n;
        $this->position  += $n;
        if (0 === $this->remaining) {
            if (null === $this->length) {
                $this->chunkEnd = true;
            } else {
                $this->done = true;
            }
        }

        return $bytes;
    }

    /**
     * The client is waiting for `100 Continue`, and the application never read the body, so
     * it may never arrive.
     */
    public function continuePending(): bool
    {
        return $this->continue;
    }

    /**
     * Whether discard($max) may keep the connection, as far as is known without reading.
     */
    public function discardable(int $max): bool
    {
        return $this->done || (null === $this->error && !$this->continue && (null === $this->length || $this->remaining <= $max));
    }

    /**
     * Read and throw away what the application left unread, so the next request starts in
     * the right place. Returns false, reading nothing more, when that would cost more than
     * $max bytes, the body is malformed, or the client still waits for `100 Continue`: the
     * connection must then close.
     */
    public function discard(int $max): bool
    {
        if ($this->done) {
            return true;
        }
        if (!$this->discardable($max)) {
            return false;
        }
        try {
            while (!$this->done) {
                if (($max -= \strlen($this->read(65536))) < 0) {
                    return false;
                }
            }
        } catch (HttpError) {
            return false;
        }

        return true;
    }

    /**
     * The response is written: later reads are a bug (the next request's bytes are next on
     * the socket), so they throw.
     */
    public function finish(): void
    {
        $this->finished = true;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->done) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function eof(): bool
    {
        return $this->done;
    }

    public function getSize(): ?int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * Only to where the body already is: nothing to do. Slim's MethodOverrideMiddleware
     * rewinds every POST body at its end, which an empty body is before it is read.
     */
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $to = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $this->position + $offset,
            default   => null,
        };
        if ($to !== $this->position) {
            throw new \RuntimeException('The request body is not seekable');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('The request body is not writable');
    }

    /**
     * Does nothing: the connection decides whether to skip the rest of the body or close.
     */
    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }

    /**
     * Read the next chunk's size line (after the previous chunk's CRLF). At the last chunk,
     * read and discard the trailer fields and mark the body done.
     *
     * @throws HttpError on any framing violation
     */
    private function nextChunk(): void
    {
        if ($this->chunkEnd) {
            $this->connection->readLine(0); // exactly CRLF after the chunk's data
            $this->chunkEnd = false;
        }
        $line   = $this->connection->readLine(NativeHttpConnection::MAX_CHUNK_LINE);
        $digits = \strspn($line, '0123456789abcdefABCDEF');
        // At most 15 hex digits, so the size always fits an int; extensions are accepted and ignored
        if (0 === $digits || $digits > 15 || ($digits !== \strlen($line)
            && 1 !== \preg_match('/\A[ \t]*;[\t\x20-\x7E\x80-\xFF]*\z/', \substr($line, $digits)))) {
            throw new HttpError('Bad Request', 400);
        }
        $this->remaining = \hexdec(\substr($line, 0, $digits));
        if ($this->remaining > $this->maxSize - $this->position) {
            throw new HttpError('Content Too Large', 413);
        }
        if (0 === $this->remaining) {
            $total = 0;
            $count = 0;
            while ('' !== ($trailer = $this->connection->readLine(NativeHttpConnection::MAX_CHUNK_LINE))) {
                $total += \strlen($trailer) + 2;
                if (++$count > NativeHttpConnection::MAX_HEADERS || $total > NativeHttpConnection::MAX_HEAD
                    || 1 !== \preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[\t\x20-\x7E\x80-\xFF]*\z/', $trailer)) {
                    throw new HttpError('Bad Request', 400);
                }
            }
            $this->done = true;
        }
    }
}
