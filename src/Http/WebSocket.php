<?php

namespace Swerve\Http;

use phasync;
use phasync\CancelledException;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use phasync\Psr\Response;
use phasync\Util\Event;

/**
 * A WebSocket connection (RFC 6455), server side.
 *
 * ```php
 * return WebSocket::from($request, function (WebSocket $ws) {
 *     $ws->onMessage->listen(function (string $data) {      // inbound: events
 *         Swerve::publish('chat', $data);
 *     });
 *     foreach (Swerve::subscribe('chat') as $out) {         // outbound: plain sequential code
 *         if ('end' === $out) {
 *             return;                                       // returning closes the socket (1000)
 *         }
 *         $ws->send($out);
 *     }
 * });
 * ```
 *
 * The callback runs in a coroutine of its own after the handshake; the connection closes when
 * it returns (1000), or throws (1011, and swerve logs the exception). end() closes it early. A
 * request that is not a WebSocket handshake is answered 426 or 400.
 *
 * The connection is read all the time, also when the callback only sends: pings from the client
 * are answered, and when the client leaves the callback is cancelled, so the loop above ends
 * with its client. A message goes to the $onMessage listeners, one at a time, in the order they
 * arrived: a slow listener holds back the reading. The server pings every connection every
 * PING_INTERVAL seconds, from one coroutine for all of them, so proxies don't close a quiet one.
 *
 * The pull style is the alternative to $onMessage, and the two don't mix: receive() throws
 * while $onMessage has listeners.
 *
 * ```php
 * foreach ($ws as $message) {               // ends when the connection closes
 *     $ws->send("echo: $message");
 * }
 * ```
 *
 * `send()` and `end()` may be called from any coroutine.
 *
 * @see Swerve\Http\ProtocolUpgrade
 * @see Swerve::publish
 * @see Swerve::subscribe
 */
class WebSocket extends ProtocolUpgrade implements \IteratorAggregate
{
    /** The largest message received; a larger one closes the connection with 1009. */
    public const MAX_MESSAGE = 1 << 20;

    /** Seconds between the pings the server sends to every open connection. */
    public const PING_INTERVAL = 15.0;

    /** Messages received and not yet taken by receive(); the reader waits while there are INBOX. */
    public const INBOX = 64;

    /**
     * Called with `(string $data, bool $binary)` for each text or binary message received.
     *
     * The listeners are called one at a time, in the order the messages arrived, in the
     * coroutine that reads the connection: a slow listener holds back the reading. A listener
     * that throws closes the connection with 1011 and the exception is logged.
     *
     * ```php
     * $ws->onMessage->listen(function (string $data, bool $binary) use ($ws) {
     *     $ws->send("got " . strlen($data) . " bytes");
     * });
     * ```
     */
    public readonly Event $onMessage;

    /**
     * Called once with `(int $code, string $reason)` when the connection has ended, whichever side ended it.
     *
     * The code is the client's (1005 when it sent none), the one given to {@see WebSocket::end()},
     * 1011 after an exception, or 1006 when the connection ended without a close frame.
     *
     * ```php
     * $ws->onClose->listen(function (int $code, string $reason) {
     *     Swerve::log()->info('websocket closed: {code} {reason}', ['code' => $code, 'reason' => $reason]);
     * });
     * ```
     */
    public readonly Event $onClose;

    private string $buffer = '';
    private bool $closed   = false;
    private bool $binary   = false;

    /** How the connection ended, set by whoever ended it first, see note(). */
    private ?int $code     = null;
    private string $reason = '';

    /** @var list<array{0: string, 1: bool}> */
    private array $inbox = [];

    /** @var array<int, self> the open WebSockets of this process, see keepAlive() */
    private static array $open = [];

    /** Raised when the last open WebSocket closes, for the ping loop. */
    private static ?object $lastClosed = null;
    private static bool $pinging = false;

    /** The reader saw the connection end: receive() returns null once the inbox is empty. */
    private bool $readerDone = false;

    protected function __construct(StreamInterface $in)
    {
        parent::__construct($in);
        $this->onMessage = new Event();
        $this->onClose   = new Event();
    }

    /**
     * The next text or binary message, or null once the connection is closed.
     *
     * Closed by the client, by `end()`, because the client broke the protocol, or because swerve
     * drains (a shutdown or reload), which closes it with 1001. Waits for a message. It is the
     * alternative to `$onMessage`, and the two don't mix.
     *
     * ```php
     * while (null !== ($message = $ws->receive())) {
     *     $ws->send(strrev($message));
     * }
     * ```
     *
     * @throws \LogicException while `$onMessage` has listeners: they get the messages
     *
     * @see WebSocket::isBinary
     */
    public function receive(): ?string
    {
        if ($this->onMessage->hasListeners()) {
            throw new \LogicException('receive() and $onMessage are alternatives: the listeners get the messages');
        }
        while (!$this->inbox && !$this->readerDone) {
            phasync::awaitFlag($this);
        }
        if (!$this->inbox) {
            return null;
        }
        [$message, $this->binary] = \array_shift($this->inbox);
        phasync::raiseFlag($this); // the reader may wait for room

        return $message;
    }

    /**
     * The next message from the client, as [message, binary], or null once the connection is
     * closed. Pings are answered and fragments joined on the way.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function readMessage(): ?array
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
                    $this->end(1002);

                    return null;
                }
                if ($length > self::MAX_MESSAGE - \strlen($message ?? '')) {
                    $this->end(1009);

                    return null;
                }
                $mask    = $this->need(4);
                $payload = $length > 0 ? $this->need($length) ^ \substr(\str_repeat($mask, \intdiv($length, 4) + 1), 0, $length) : '';
            } catch (\UnderflowException) {
                $this->note(1006, ''); // no close frame came
                $this->end(1001);      // the client's side ended, or swerve drains: going away

                return null;
            }
            if (8 === $opcode) {
                $code = $length >= 2 ? \unpack('n', $payload)[1] : 1005;
                if (1 !== $length) {
                    $this->note($code, \substr($payload, 2));
                }
                $this->end(1 === $length ? 1002 : (1005 === $code ? 1000 : $code));

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
                    if ($text && !\preg_match('//u', $message)) {
                        $this->end(1007); // text must be UTF-8

                        return null;
                    }

                    return [$message, !$text];
                }
            }
        }

        return null;
    }

    /**
     * The messages received, until the connection closes: `foreach ($ws as $message)`.
     *
     * Each step is a {@see WebSocket::receive()}, so it throws the same `LogicException` while
     * `$onMessage` has listeners.
     *
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        while (null !== ($message = $this->receive())) {
            yield $message;
        }
    }

    /**
     * Whether the message `receive()` returned last was binary, rather than text.
     *
     * @see WebSocket::receive
     */
    public function isBinary(): bool
    {
        return $this->binary;
    }

    /**
     * Send a text message; nothing happens once the connection is closed.
     *
     * May be called from any coroutine. Waits while the client reads slowly, and gives the
     * client up after `ProtocolUpgrade::WRITE_TIMEOUT` seconds.
     *
     * ```php
     * $ws->send(json_encode(['type' => 'welcome']));
     * ```
     *
     * @param string $text the message, which must be UTF-8
     *
     * @see WebSocket::sendBinary
     */
    public function send(string $text): void
    {
        $this->frame(1, $text);
    }

    /**
     * Send a binary message; nothing happens once the connection is closed.
     *
     * ```php
     * $ws->sendBinary(pack('N', 42));
     * ```
     *
     * @param string $data the message
     *
     * @see WebSocket::send
     */
    public function sendBinary(string $data): void
    {
        $this->frame(2, $data);
    }

    /**
     * Close the connection: send a close frame with `$code` and `$reason`, once.
     *
     * `receive()` returns null from then on, and the connection closes after the client's own
     * goodbye, or a moment without one. `$onClose` fires once, with the code and reason of whoever
     * said goodbye first. Returning from the callback does the same with 1000. May be called from
     * any coroutine.
     *
     * ```php
     * $ws->onMessage->listen(function (string $data) use ($ws) {
     *     if ('bye' === $data) {
     *         $ws->end(1000, 'bye');
     *     }
     * });
     * ```
     *
     * @param int    $code   the close code (RFC 6455, section 7.4): 1000 is a normal closure
     * @param string $reason the close frame's reason text
     */
    public function end(int $code = 1000, string $reason = ''): void
    {
        if (!$this->closed) {
            $this->note($code, $reason);
            $this->frame(8, \pack('n', $code) . $reason);
            $this->closed = true;
            parent::end();
        }
    }

    /** Remember how the connection ended, if nobody did yet; 1005 is "no code", 1006 "no goodbye". */
    private function note(int $code, string $reason): void
    {
        if (null === $this->code) {
            $this->code   = $code;
            $this->reason = $reason;
        }
    }

    /**
     * Whether the connection is closed: `end()` was called, the client said goodbye, or the connection ended.
     *
     * @see WebSocket::end
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    protected function handshake(ServerRequestInterface $request): array|ResponseInterface
    {
        if ('websocket' !== \strtolower($request->getHeaderLine('Upgrade'))) {
            return new Response(426, ['Content-Type' => 'text/plain', 'Upgrade' => 'websocket'], 'This address speaks WebSocket');
        }
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        if ('GET' !== $request->getMethod() || '13' !== $request->getHeaderLine('Sec-WebSocket-Version') || 16 !== \strlen((string) \base64_decode($key, true))) {
            return new Response(400, ['Content-Type' => 'text/plain', 'Sec-WebSocket-Version' => '13'], 'Not a WebSocket handshake');
        }

        return ['Upgrade' => 'websocket', 'Sec-WebSocket-Accept' => \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))];
    }

    /**
     * Read the connection while the callback runs in a coroutine of its own.
     *
     * Messages go to the `$onMessage` listeners, or to the inbox for receive() when there are none; pings are
     * answered, and when the connection ends (the client left or closed, or swerve drains) the
     * callback is cancelled if it still runs. So a callback that only sends, such as one
     * forwarding a subscription, ends when its client is gone.
     */
    protected function run(\Closure $callback): void
    {
        $app = phasync::go(function () use ($callback) {
            try {
                $callback($this);
                $this->end(1000);
            } catch (CancelledException $e) {
                if (!$this->readerDone) {
                    $this->end(1011);
                    throw $e;
                }
                // The connection ended, and the reader stopped the callback: nothing went wrong
            } catch (\Throwable $e) {
                $this->end(1011);
                throw $e;
            } finally {
                phasync::raiseFlag($this); // the reader may wait for room nobody will make now
            }
        });
        self::$open[\spl_object_id($this)] = $this;
        self::keepAlive();
        $failure = null; // the first exception wins: a listener's, then $onClose's
        try {
            while (null !== ($message = $this->readMessage())) {
                if ($this->onMessage->hasListeners()) {
                    try {
                        if (!$app->isTerminated()) {
                            $this->onMessage->trigger($message[0], $message[1]);
                        }
                    } catch (\Throwable $e) {
                        $this->end(1011);

                        throw $e;
                    }

                    continue;
                }
                while (\count($this->inbox) >= self::INBOX && !$app->isTerminated()) {
                    phasync::awaitFlag($this); // the client sends faster than the callback receives
                }
                if (!$app->isTerminated()) {
                    $this->inbox[] = $message;
                    phasync::raiseFlag($this);
                }
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $this->readerDone = true;
        phasync::raiseFlag($this);
        unset(self::$open[\spl_object_id($this)]);
        if (!self::$open && null !== self::$lastClosed) {
            phasync::raiseFlag(self::$lastClosed); // the ping loop ends now, not after its sleep
        }
        try {
            $this->onClose->trigger($this->code ?? 1006, $this->reason);
        } catch (\Throwable $e) {
            $failure ??= $e;
        }
        if (!$app->isTerminated()) {
            phasync::cancel($app);
        }
        if (null !== $failure) {
            throw $failure;
        }
        phasync::await($app); // its exception, if any, for swerve's log
    }

    /**
     * Ping every open WebSocket of this process every PING_INTERVAL seconds, from one coroutine
     * for all of them, which ends when none is open. A ping doesn't wait for a client that reads
     * slowly: that one's own sends time out and give it up.
     */
    private static function keepAlive(): void
    {
        if (self::$pinging) {
            return;
        }
        self::$pinging = true;
        // A context of its own: it serves every connection, and no request waits for it (a
        // drain waits for the coroutines of requests)
        phasync::go(static function () {
            try {
                while (self::$open) {
                    try {
                        phasync::awaitFlag(self::$lastClosed ??= new \stdClass(), self::PING_INTERVAL);
                    } catch (TimeoutException) {
                        foreach (self::$open as $ws) {
                            if (!$ws->closed) {
                                $ws->writeNow("\x89\x00"); // a ping with no payload
                            }
                        }
                    }
                }
            } finally {
                self::$pinging = false;
            }
        }, context: new \stdClass());
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
