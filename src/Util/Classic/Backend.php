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
        \stream_set_blocking($stream, false);

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
        while ('' !== $bytes) {
            $n = @\fwrite($this->stream, $bytes);
            if (false === $n) {
                throw new IOException('The request server closed the connection');
            }
            if ($n < \strlen($bytes)) {
                $bytes = \substr($bytes, $n);
                \phasync::writable($this->stream);
            } else {
                return;
            }
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

    public function close(): void
    {
        \fclose($this->stream);
    }

    private function fill(): void
    {
        $bytes = \fread($this->stream, 262144);
        if ('' === $bytes || false === $bytes) {
            if (\feof($this->stream)) {
                throw new IOException('The request server closed the connection');
            }
            \phasync::readable($this->stream);

            return;
        }
        $this->buffer .= $bytes;
    }
}
