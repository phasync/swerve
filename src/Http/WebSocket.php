<?php

namespace Swerve\Http;

use phasync;
use phasync\CancelledException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\Message\Response;

/**
 * A WebSocket connection (RFC 6455), server side.
 *
 *     return WebSocket::from($request, function (WebSocket $ws) {
 *         foreach ($ws as $message) {           // ends when the connection closes
 *             $ws->send("echo: $message");
 *         }
 *     });
 *
 * The callback runs in a coroutine of its own after the handshake; the connection closes when
 * it returns (1000), or throws (1011, and swerve logs the exception). A request that is not a
 * WebSocket handshake is answered 426 or 400.
 *
 * One coroutine receives; send() and close() may be called from any. Pings from the client are
 * answered and fragmented messages joined while receiving. The server pings every
 * PING_INTERVAL seconds, so proxies don't close a quiet connection.
 */
class WebSocket extends ProtocolUpgrade implements \IteratorAggregate
{
    /** The largest message received; a larger one closes the connection with 1009. */
    public const MAX_MESSAGE = 1 << 20;

    public const PING_INTERVAL = 15.0;

    private string $buffer = '';
    private bool $closed   = false;
    private bool $binary   = false;

    /**
     * The next text or binary message, or null once the connection is closed: by the client, by
     * close(), because the client broke the protocol, or because swerve drains (a shutdown or
     * reload), which closes it with 1001.
     */
    public function receive(): ?string
    {
        $message = null; // a fragmented message in progress
        $text    = false;
        while (!$this->closed) {
            try {
                $head   = $this->need(2);
                $fin    = (\ord($head[0]) & 0x80) !== 0;
                $opcode = \ord($head[0]) & 0x0F;
                $length = \ord($head[1]) & 0x7F;
                if (126 === $length) {
                    $length = \unpack('n', $this->need(2))[1];
                } elseif (127 === $length) {
                    $length = \unpack('J', $this->need(8))[1];
                }
                $control = $opcode >= 8;
                if (
                    (\ord($head[0]) & 0x70)                               // reserved bits, no extension agreed
                    || !(\ord($head[1]) & 0x80)                           // clients must mask
                    || ($control ? !$fin || $length > 125 || $opcode > 10 // control frames: whole and short
                        : $opcode > 2 || (0 === $opcode) !== (null !== $message)) // a continuation continues something
                ) {
                    $this->close(1002);

                    return null;
                }
                if ($length > self::MAX_MESSAGE - \strlen($message ?? '')) {
                    $this->close(1009);

                    return null;
                }
                $mask    = $this->need(4);
                $payload = $length > 0 ? $this->need($length) ^ \substr(\str_repeat($mask, \intdiv($length, 4) + 1), 0, $length) : '';
            } catch (\UnderflowException) {
                $this->close(1001); // the client's side ended, or swerve drains: going away

                return null;
            }
            if (8 === $opcode) {
                $this->close(1 === $length ? 1002 : ($length >= 2 ? \unpack('n', $payload)[1] : 1000));

                return null;
            }
            if (9 === $opcode) {
                $this->frame(10, $payload);
            } elseif (10 !== $opcode) {
                if (0 !== $opcode) {
                    $text = 1 === $opcode;
                }
                $message = ($message ?? '') . $payload;
                if ($fin) {
                    $this->binary = !$text;
                    if ($text && !\preg_match('//u', $message)) {
                        $this->close(1007); // text must be UTF-8

                        return null;
                    }

                    return $message;
                }
            }
        }

        return null;
    }

    /**
     * The messages received, until the connection closes.
     *
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        while (null !== ($message = $this->receive())) {
            yield $message;
        }
    }

    /** Whether the message receive() returned last was binary, rather than text. */
    public function isBinary(): bool
    {
        return $this->binary;
    }

    /** Send a text message, which must be UTF-8; nothing once the connection is closed. */
    public function send(string $text): void
    {
        $this->frame(1, $text);
    }

    /** Send a binary message; nothing once the connection is closed. */
    public function sendBinary(string $data): void
    {
        $this->frame(2, $data);
    }

    /**
     * Say goodbye, once: receive() returns null from then on, and the connection closes after
     * the client's own goodbye, or a moment without one.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->closed) {
            $this->frame(8, \pack('n', $code) . $reason);
            $this->closed = true;
            $this->end();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    protected function handshake(ServerRequestInterface $request): array|ResponseInterface
    {
        if ('websocket' !== \strtolower($request->getHeaderLine('Upgrade'))) {
            return new Response('This address speaks WebSocket', ['Content-Type' => 'text/plain', 'Upgrade' => 'websocket'], 426);
        }
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        if ('GET' !== $request->getMethod() || '13' !== $request->getHeaderLine('Sec-WebSocket-Version') || 16 !== \strlen((string) \base64_decode($key, true))) {
            return new Response('Not a WebSocket handshake', ['Content-Type' => 'text/plain', 'Sec-WebSocket-Version' => '13'], 400);
        }

        return ['Upgrade' => 'websocket', 'Sec-WebSocket-Accept' => \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))];
    }

    protected function run(\Closure $callback): void
    {
        $keepalive = phasync::go(function () {
            try {
                while (!$this->closed) {
                    phasync::sleep(static::PING_INTERVAL);
                    $this->frame(9, '');
                }
            } catch (CancelledException) {
                // The connection ended
            }
        });
        try {
            $callback($this);
            $this->close(1000);
        } catch (\Throwable $e) {
            $this->close(1011);
            throw $e;
        } finally {
            if (!$keepalive->isTerminated()) {
                phasync::cancel($keepalive);
            }
        }
    }

    private function frame(int $opcode, string $payload): void
    {
        if ($this->closed) {
            return;
        }
        $n = \strlen($payload);
        if (!$this->write(\chr(0x80 | $opcode) . ($n < 126 ? \chr($n) : ($n < 65536 ? \chr(126) . \pack('n', $n) : \chr(127) . \pack('J', $n))) . $payload)) {
            $this->closed = true; // the client stopped reading, and was given up
        }
    }

    /** $n bytes from the client; UnderflowException when its side ended first. */
    private function need(int $n): string
    {
        while (\strlen($this->buffer) < $n) {
            $bytes = $this->read(65536);
            if ('' === $bytes) {
                throw new \UnderflowException("The client's side ended");
            }
            $this->buffer .= $bytes;
        }
        $bytes        = \substr($this->buffer, 0, $n);
        $this->buffer = \substr($this->buffer, $n);

        return $bytes;
    }
}
