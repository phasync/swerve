<?php

namespace Swerve\Util\Classic;

use phasync\IOException;

/**
 * One connection to ext-phasync's request server, for one request: a child of the server
 * accepted it and serves it. Frames: `[type: 1 byte][length: 4 bytes, big-endian][payload]`.
 *
 * @internal
 */
final class Backend
{
    public const HEAD  = 1;
    public const BODY  = 2;
    public const STOP  = 3;
    public const ABORT = 4;

    private string $buffer = '';

    /** @param resource $stream */
    private function __construct(private $stream)
    {
    }

    /**
     * @throws IOException when the server can't be reached
     */
    public static function connect(string $socket): self
    {
        $stream = @\stream_socket_client("unix://$socket", $errno, $error, 5);
        if (false === $stream) {
            throw new IOException("Can't connect to the request server at $socket: $error");
        }
        // Blocking, with no timeout of its own: ext-phasync makes the waits cooperative, and a script
        // runs as long as its max_execution_time lets it
        \stream_set_timeout($stream, 86400 * 365);

        return new self($stream);
    }

    /**
     * Write one or more frames, each `[type, payload]`, in one write.
     *
     * @param list<array{int, string}> $frames
     *
     * @throws IOException when the connection is gone
     */
    public function send(array $frames): void
    {
        $bytes = '';
        foreach ($frames as [$type, $payload]) {
            $bytes .= \pack('CN', $type, \strlen($payload)) . $payload;
        }
        $written = @\fwrite($this->stream, $bytes);
        if (false === $written) {
            throw new IOException('The request server closed the connection');
        }
        if (\strlen($bytes) !== $written) {
            throw new \LogicException("A blocking write to the request server wrote $written of " . \strlen($bytes) . ' bytes');
        }
    }

    /**
     * The next frame.
     *
     * @return array{int, string} type and payload
     *
     * @throws IOException when the connection ends first
     */
    public function receive(): array
    {
        while (\strlen($this->buffer) < 5 || \strlen($this->buffer) < 5 + \unpack('N', $this->buffer, 1)[1]) {
            $this->fill();
        }
        $length       = \unpack('N', $this->buffer, 1)[1];
        $frame        = [\ord($this->buffer[0]), \substr($this->buffer, 5, $length)];
        $this->buffer = \substr($this->buffer, 5 + $length);

        return $frame;
    }

    /**
     * The next frame when it has arrived whole already, without waiting; null otherwise.
     *
     * @return array{int, string}|null type and payload
     */
    public function buffered(): ?array
    {
        if (\strlen($this->buffer) < 5 || \strlen($this->buffer) < 5 + \unpack('N', $this->buffer, 1)[1]) {
            return null;
        }

        return $this->receive();
    }

    public function close(): void
    {
        \fclose($this->stream);
    }

    private function fill(): void
    {
        $bytes = \fread($this->stream, 262144);
        if ('' === $bytes || false === $bytes) {
            throw new IOException('The request server closed the connection');
        }
        $this->buffer .= $bytes;
    }
}
