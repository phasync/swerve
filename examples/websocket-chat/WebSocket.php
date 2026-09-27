<?php

use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Swerve\Http\Message\Response;

/**
 * A small WebSocket server connection (RFC 6455), written against swerve's two streams: the
 * request body is what the client sends after the handshake, the response body what goes
 * back. Example code: a WebSocket package for swerve is planned.
 *
 *     return WebSocket::upgrade($request, function (WebSocket $ws) {
 *         while (null !== ($message = $ws->receive())) {
 *             $ws->send("echo: $message");
 *         }
 *     });
 *
 * $handler runs in a coroutine of its own; the connection closes when it returns. receive()
 * answers pings and closes by itself; send() may be called from any coroutine.
 */
final class WebSocket
{
    /** The largest message received; a larger one closes the connection with 1009. */
    public const MAX_MESSAGE = 1 << 20;

    /** How long send() waits for a client that stopped reading before giving it up. */
    public const SEND_TIMEOUT = 30.0;

    /**
     * How often a ping goes to the client, which answers with a pong: a quiet connection still
     * carries something both ways. Without it, swerve's read of the response stream gives up
     * after SEND_TIMEOUT with nothing to send, and proxies close quiet connections too.
     */
    public const PING_INTERVAL = 15.0;

    private string $buffer = '';
    private bool $closed   = false;

    private function __construct(private readonly StreamInterface $in, private readonly UnbufferedStream $out)
    {
    }

    /**
     * Answer the handshake with 101 and run $handler on the connection, or answer 400 when the
     * request is not a WebSocket handshake.
     *
     * @param Closure(WebSocket): void $handler
     */
    public static function upgrade(ServerRequestInterface $request, Closure $handler): ResponseInterface
    {
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        if ('websocket' !== \strtolower($request->getHeaderLine('Upgrade')) || 16 !== \strlen((string) \base64_decode($key, true))) {
            return new Response('Not a WebSocket handshake', ['Content-Type' => 'text/plain'], 400);
        }
        $ws = new self($request->getBody(), new UnbufferedStream(65536, self::SEND_TIMEOUT));
        phasync::go(static function () use ($ws, $handler) {
            $keepalive = phasync::go(static function () use ($ws) {
                try {
                    while (!$ws->closed) {
                        phasync::sleep(self::PING_INTERVAL);
                        $ws->frame(9, '');
                    }
                } catch (\phasync\CancelledException) {
                    // The connection ended
                }
            });
            try {
                $handler($ws);
                $ws->close(1000);
            } catch (Throwable $e) {
                $ws->close(1011);
                throw $e; // logged by swerve
            } finally {
                if (!$keepalive->isTerminated()) {
                    phasync::cancel($keepalive);
                }
                $ws->out->end();
            }
        });

        return new Response($ws->out, [
            'Upgrade'              => 'websocket',
            'Connection'           => 'Upgrade',
            'Sec-WebSocket-Accept' => \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ], 101);
    }

    /**
     * The next text or binary message, or null once the connection is closed: by the client, by
     * close(), or because swerve drains (shutdown, reload), which ends the request body.
     */
    public function receive(): ?string
    {
        $message = '';
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
                if (!(\ord($head[1]) & 0x80) || \strlen($message) + $length > self::MAX_MESSAGE) {
                    $this->close(!(\ord($head[1]) & 0x80) ? 1002 : 1009); // clients must mask; too large

                    return null;
                }
                $mask    = $this->need(4);
                $payload = $length > 0 ? $this->need($length) : '';
                $payload ^= \substr(\str_repeat($mask, \intdiv($length, 4) + 1), 0, $length);
            } catch (UnderflowException) {
                $this->close(1001); // the client's side ended, or swerve drains: going away

                return null;
            } catch (RuntimeException) {
                $this->closed = true; // the connection broke: nobody to say goodbye to

                return null;
            }
            if (8 === $opcode) {
                $this->close(\strlen($payload) >= 2 ? \unpack('n', $payload)[1] : 1000);

                return null;
            }
            if (9 === $opcode) {
                $this->frame(10, $payload);
            } elseif (10 !== $opcode) {
                $message .= $payload; // text, binary, or a continuation of either
                if ($fin) {
                    return $message;
                }
            }
        }

        return null;
    }

    /** Send a text message; nothing once the connection is closed. */
    public function send(string $text): void
    {
        $this->frame(1, $text);
    }

    /** Say goodbye, once; receive() returns null from then on. */
    public function close(int $code = 1000): void
    {
        if (!$this->closed) {
            $this->frame(8, \pack('n', $code));
            $this->closed = true;
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function frame(int $opcode, string $payload): void
    {
        if ($this->closed) {
            return;
        }
        $n = \strlen($payload);
        try {
            $this->out->append(\chr(0x80 | $opcode) . ($n < 126 ? \chr($n) : ($n < 65536 ? \chr(126) . \pack('n', $n) : \chr(127) . \pack('J', $n))) . $payload);
        } catch (phasync\TimeoutException) {
            // The client stopped reading: give it up. Closing the request body ends the
            // connection at once.
            $this->closed = true;
            $this->in->close();
        }
    }

    /** $n bytes from the client; UnderflowException when its side ended first. */
    private function need(int $n): string
    {
        while (\strlen($this->buffer) < $n) {
            $data = $this->in->read(65536);
            if ('' === $data) {
                throw new UnderflowException('The client\'s side ended');
            }
            $this->buffer .= $data;
        }
        $bytes        = \substr($this->buffer, 0, $n);
        $this->buffer = \substr($this->buffer, $n);

        return $bytes;
    }
}
