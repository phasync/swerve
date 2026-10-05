<?php

namespace Swerve\Psr;

use phasync\IOException;
use Psr\Http\Message\StreamInterface;
use Swerve\ClientRequest;

/**
 * A request's body as a PSR-7 stream, read from the {@see ClientRequest} as the application reads it.
 *
 * Nothing is buffered and nothing is read ahead: `read()` waits in phasync's event loop for the
 * next bytes of the body, and returns '' only at its end. The body is therefore only as large in
 * memory as the application makes it, and `Expect: 100-continue` is answered when the application
 * first reads. The stream is not seekable, and cannot be read once the exchange is over.
 *
 * `close()` does nothing: the exchange owns the connection, and a framework that tidies up by
 * closing the request's body must not end the response with it.
 *
 * A framework adapter (swerve-psr15, Symfony, Tether) builds one of these for a {@see ClientRequest}'s
 * body, the same way for every framework: {@see FormBody} is its form data, parsed the same way too.
 */
final class RequestBody implements StreamInterface
{
    private int $position = 0;

    /**
     * @param ClientRequest $request the exchange whose body this is
     * @param int|null      $size    the body's size when the request declared it, null when it is chunked
     */
    public function __construct(private readonly ClientRequest $request, private readonly ?int $size)
    {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->request->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('A request body is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('A request body is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('A request body is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /**
     * @throws \RuntimeException when the client went away before the body ended
     */
    public function read($length): string
    {
        try {
            $bytes = $this->request->read($length);
        } catch (IOException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
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
