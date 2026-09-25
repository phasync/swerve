<?php

namespace Swerve\Http;

use Psr\Http\Message\StreamInterface;

/**
 * The body of a request in --native-http mode, read straight from the connection as the
 * application reads it: a Content-Length body, or a chunked one decoded on the fly. Bytes
 * already in the connection's buffer are used first; nothing is copied ahead.
 */
final class RequestBody implements StreamInterface
{
    /** Content-Length bytes not read yet; unused for a chunked body. */
    private int $remaining;

    /** Bytes left of the current chunk of a chunked body. */
    private int $chunkRemaining = 0;

    /** A chunk's trailing CRLF is still in the buffer. */
    private bool $chunkEndPending = false;

    private bool $done;
    private int $position = 0;

    public function __construct(
        private readonly NativeHttpConnection $connection,
        private readonly ?int $length,
        private readonly bool $chunked,
    ) {
        $this->remaining = \max(0, $length ?? 0);
        $this->done      = !$chunked && 0 === $this->remaining;
    }

    public function read(int $length): string
    {
        if ($this->done || $length <= 0) {
            return '';
        }
        $available = $this->chunked ? $this->nextChunkBytes() : $this->remaining;
        if (0 === $available) {
            return '';
        }
        if ('' === $this->connection->buffer) {
            $this->connection->fill();
        }
        $take  = \min($length, $available, \strlen($this->connection->buffer));
        $bytes = \substr($this->connection->buffer, 0, $take);
        $this->connection->buffer = \substr($this->connection->buffer, $take);
        $this->position += $take;
        if ($this->chunked) {
            $this->chunkRemaining -= $take;
            $this->chunkEndPending = 0 === $this->chunkRemaining;
        } else {
            $this->remaining -= $take;
            $this->done = 0 === $this->remaining;
        }

        return $bytes;
    }

    /**
     * Read and discard whatever the application left unread.
     */
    public function discard(): void
    {
        while (!$this->done) {
            $this->read(65536);
        }
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
        return $this->chunked ? null : $this->length;
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

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('The request body is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('The request body is not seekable');
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('The request body is not writable');
    }

    public function close(): void
    {
        $this->discard();
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
     * Bytes left in the current chunk, reading the next chunk's size line when the current
     * one is used up. 0 at the end of the body.
     */
    private function nextChunkBytes(): int
    {
        if ($this->chunkRemaining > 0) {
            return $this->chunkRemaining;
        }
        if ($this->chunkEndPending) {
            $this->consumeLine(); // the CRLF after the chunk's data
            $this->chunkEndPending = false;
        }
        $size = \hexdec(\trim(\strtok($this->consumeLine(), ';')));
        if (0 === $size) {
            // Trailer fields, if any, up to an empty line
            while ('' !== $this->consumeLine()) {
            }
            $this->done = true;

            return 0;
        }
        $this->chunkRemaining = (int) $size;

        return $this->chunkRemaining;
    }

    private function consumeLine(): string
    {
        while (false === ($end = \strpos($this->connection->buffer, "\r\n"))) {
            $this->connection->fill();
        }
        $line = \substr($this->connection->buffer, 0, $end);
        $this->connection->buffer = \substr($this->connection->buffer, $end + 2);

        return $line;
    }
}
