<?php

namespace Swerve;

use phasync;
use phasync\CancelledException;
use phasync\IOException;
use phasync\Net\Duplex;
use phasync\TimeoutException;
use phasync\Util\Event;

/**
 * A WebSocket connection (RFC 6455), server side, on a {@see ClientRequest} or any {@see Duplex}.
 *
 * ```php
 * return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
 *     Swerve\WebSocket::from($request, function (Swerve\WebSocket $ws) {
 *         $ws->onMessage->listen(function (string $data) {      // inbound: events
 *             Swerve::publish('chat', $data);
 *         });
 *         foreach (Swerve::subscribe('chat') as $out) {         // outbound: plain sequential code
 *             if ('end' === $out) {
 *                 return;                                       // returning closes the socket (1000)
 *             }
 *             $ws->send($out);
 *         }
 *     });
 * });
 * ```
 *
 * {@see WebSocket::from()} checks the handshake (and answers 426, 400 or 403 itself when it is
 * not one), then runs the callback in a coroutine of its own. The connection closes when the
 * callback returns (1000) or throws (1011, and swerve logs the exception); {@see WebSocket::end()}
 * closes it early. The connection is read all the time, also when the callback only sends: pings
 * are answered, and when the client leaves the callback is cancelled, so the loop above ends with
 * its client. The server pings every connection every {@see WebSocket::$pingInterval} seconds, from
 * one coroutine for all of them, so proxies don't close a quiet one.
 *
 * A message goes to the `$onMessage` listeners, one at a time, in the order it arrived: a slow
 * listener holds back the reading. The pull style is the alternative, and the two don't mix:
 * receive() throws while `$onMessage` has listeners.
 *
 * ```php
 * foreach ($ws as $message) {               // ends when the connection closes
 *     $ws->send("echo: $message");
 * }
 * ```
 *
 * `send()` and `end()` may be called from any coroutine. A client that reads slower than the
 * callback sends makes `send()` wait; one that sends faster than the callback receives is not
 * read from, so TCP holds it back. Neither makes the worker buffer.
 *
 * {@see WebSocket::accept()} is the lower level: the handler itself reads with receive(), as
 * `$onMessage` is not called then, and calls end() when it is done.
 *
 * The contract, with the close codes, the limits and what drains do, is in docs/websocket.md.
 *
 * @see ServerSentEvents
 * @see Swerve::publish
 * @see Swerve::subscribe
 */
final class WebSocket implements \IteratorAggregate
{
    /** The largest message received by default; a larger one closes the connection with 1009. */
    public const MAX_MESSAGE = 1 << 20;

    /** Messages received and not yet taken by receive() before the reading waits, in from(). */
    public const INBOX = 64;

    /** Seconds a send() waits for a client that doesn't read; then the client is given up and the connection closed. */
    public const WRITE_TIMEOUT = 30.0;

    /** Seconds the close frame waits for a client that doesn't read. */
    public const CLOSE_TIMEOUT = 5.0;

    /**
     * Seconds between the pings the server sends to every open connection (a process-wide setting).
     */
    public static float $pingInterval = 15.0;

    /**
     * Called with `(string $data, bool $binary)` for each text or binary message received, in from().
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
     * 1011 after an exception, a code of the protocol (1002, 1007, 1009) when the client broke
     * it, or 1006 when the connection ended without a close frame, which includes a drain.
     *
     * ```php
     * $ws->onClose->listen(function (int $code, string $reason) {
     *     Swerve::log()->info('websocket closed: {code} {reason}', ['code' => $code, 'reason' => $reason]);
     * });
     * ```
     */
    public readonly Event $onClose;

    /** The subprotocol chosen from the client's offer and the server's list, or null when none was. */
    public readonly ?string $subprotocol;

    /** @var array<int, \WeakReference<self>> the open WebSockets of this process, see keepAlive() */
    private static array $open = [];

    /** Raised when the last open WebSocket ends, for the ping loop. */
    private static ?object $idle = null;
    private static bool $pinging = false;

    private string $buffer = '';
    private bool $binary   = false;

    /** end() has run: the close frame is sent or was given up on. */
    private bool $ended = false;

    /** A write failed: nothing more can be sent. */
    private bool $dead = false;

    /** How the connection ended, set by whoever ended it first, see note(). */
    private ?int $code     = null;
    private string $reason = '';

    /** The fiber that waits for the client's bytes, for end() to wake. */
    private ?\Fiber $reading = null;

    /** from() reads the connection into $inbox; accept() leaves the reading to receive(). */
    private bool $pumped = false;

    /** @var list<array{0: string, 1: bool}> */
    private array $inbox = [];

    /** The pump saw the connection end: receive() returns null once the inbox is empty. */
    private bool $readerDone = false;

    private function __construct(private readonly Duplex $connection, ?string $subprotocol, private readonly int $maxMessage)
    {
        $this->subprotocol = $subprotocol;
        $this->onMessage   = new Event();
        $this->onClose     = new Event();
    }

    /**
     * Answer the handshake and run `$callback` on the open connection; returns when the connection has closed.
     *
     * A request that is no WebSocket handshake is answered as {@see WebSocket::accept()} says, and the
     * callback is not called. Returning from the callback closes with 1000, throwing closes with
     * 1011 and is logged by swerve; the client leaving cancels it.
     *
     * ```php
     * WebSocket::from($request, function (WebSocket $ws) {
     *     foreach ($ws as $message) {
     *         $ws->send(strtoupper($message));
     *     }
     * }, origins: ['https://example.com']);
     * ```
     *
     * @param callable(WebSocket): void $callback
     * @param string[]                  $subprotocols see accept()
     * @param string[]|null             $origins      see accept()
     * @param int                       $maxMessage   see accept()
     */
    public static function from(ClientRequest $request, callable $callback, array $subprotocols = [], ?array $origins = null, int $maxMessage = self::MAX_MESSAGE): void
    {
        $handshake = self::handshakeOf($request, $subprotocols, $origins);
        if ($handshake->accepted()) {
            self::upgrade($request, $handshake->headers, $callback, $maxMessage);
        } else {
            self::refuse($request, $handshake);
        }
    }

    /**
     * Answer the handshake and return the open connection, or null after refusing the request.
     *
     * The request must be a `GET` over HTTP/1.1 with `Upgrade: websocket`, `Connection: upgrade`, version 13 and
     * a key of 16 bytes (base64), and no body. Otherwise the response is 426 (not a WebSocket
     * request at all: `Upgrade` or `Connection` is missing), 400 (a WebSocket request that is not
     * valid, naming version 13) or 403 (an `Origin` that `$origins` does not allow), and null is returned.
     * The handler returns then. No extension (permessage-deflate) is accepted.
     *
     * The caller reads with {@see WebSocket::receive()} and must call {@see WebSocket::end()} when done:
     * the close frame is written by end(), before the handler returns. `$onMessage` is not called.
     *
     * ```php
     * if (null === $ws = WebSocket::accept($request)) {
     *     return;
     * }
     * try {
     *     foreach ($ws as $message) {
     *         $ws->send($message);
     *     }
     * } finally {
     *     $ws->end();
     * }
     * ```
     *
     * @param string[]      $subprotocols the subprotocols you speak, in your order of preference: the first one the client offered is chosen, and none when they share none
     * @param string[]|null $origins      the allowed `Origin`s (compared case-insensitively), null: any. A client that sends none (not a browser) is let through.
     * @param int           $maxMessage   the largest message received, in bytes; a larger one closes with 1009
     */
    public static function accept(ClientRequest $request, array $subprotocols = [], ?array $origins = null, int $maxMessage = self::MAX_MESSAGE): ?self
    {
        $handshake = self::handshakeOf($request, $subprotocols, $origins);
        if (!$handshake->accepted()) {
            self::refuse($request, $handshake);

            return null;
        }
        $request->sendResponseHeaders(101, $handshake->headers);

        return self::open($request, self::subprotocolOf($handshake->headers), $maxMessage);
    }

    /**
     * For framework adapters: decide a handshake from the parts of any request, answering nothing.
     *
     * {@see WebSocket::from()} and {@see WebSocket::accept()} are this decision on a
     * {@see ClientRequest}, so an adapter whose framework has its own request object (PSR-7, Symfony)
     * gets the same answer, byte for byte: it sends a refusal as an ordinary response, which the
     * framework's middleware may decorate, and for an acceptance it returns a `101` response with
     * these headers and, when the connection reaches swerve, hands it to {@see WebSocket::upgrade()}.
     * Applications use `from()` and don't call this.
     *
     * @param string                      $method       the request method
     * @param string                      $version      the HTTP version without the prefix: '1.1'
     * @param array<string, list<string>> $headers      the request headers: lowercase name => the values, in order, as {@see ClientRequest::getRequestHeaders()}
     * @param bool                        $hasBody      whether the request has a body (a handshake has none)
     * @param string[]                    $subprotocols see accept()
     * @param string[]|null               $origins      see accept()
     */
    public static function handshake(string $method, string $version, array $headers, bool $hasBody, array $subprotocols = [], ?array $origins = null): WebSocketHandshake
    {
        $tokens = static function (string $name) use ($headers): array {
            $tokens = [];
            foreach ($headers[$name] ?? [] as $line) {
                foreach (\explode(',', $line) as $token) {
                    $tokens[] = \trim($token);
                }
            }

            return $tokens;
        };
        if (!\in_array('websocket', \array_map('strtolower', $tokens('upgrade')), true) || !\in_array('upgrade', \array_map('strtolower', $tokens('connection')), true)) {
            return self::refusal(426, 'This address speaks WebSocket', ['upgrade' => 'websocket', 'connection' => 'Upgrade']);
        }
        $key = $headers['sec-websocket-key'][0] ?? '';
        if (
            'GET' !== $method || '1.1' !== $version || $hasBody
            || ['13'] !== ($headers['sec-websocket-version'] ?? null)
            || 1 !== \count($headers['sec-websocket-key'] ?? []) || 16 !== \strlen((string) \base64_decode($key, true)) || \base64_encode(\base64_decode($key, true)) !== $key
        ) {
            return self::refusal(400, 'Not a WebSocket handshake', ['sec-websocket-version' => '13']);
        }
        if (null !== $origins && null !== ($origin = $headers['origin'][0] ?? null) && !\in_array(\strtolower($origin), \array_map('strtolower', $origins), true)) {
            return self::refusal(403, 'This origin may not connect', []);
        }
        $subprotocol = null;
        $offered     = $tokens('sec-websocket-protocol');
        foreach ($subprotocols as $name) {
            if (\in_array($name, $offered, true)) {
                $subprotocol = $name;
                break;
            }
        }

        return new WebSocketHandshake(101, [
            'upgrade'              => 'websocket',
            'connection'           => 'Upgrade',
            'sec-websocket-accept' => \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ] + (null === $subprotocol ? [] : ['sec-websocket-protocol' => $subprotocol]), '', $subprotocol);
    }

    /**
     * For framework adapters: send the `101` with the headers an adapter decided, and run `$callback` on the open connection.
     *
     * What {@see WebSocket::from()} does once {@see WebSocket::handshake()} accepted: the headers are
     * sent as given (the adapter's middleware may have added some), nothing is validated again, and
     * the subprotocol is the one in `sec-websocket-protocol`. Returns when the connection has closed.
     * Applications use `from()` and don't call this.
     *
     * @param array<string, string|list<string>> $headers    the `101` response's headers, including `sec-websocket-accept`
     * @param callable(WebSocket): void          $callback
     * @param int                                $maxMessage the largest message received, in bytes; a larger one closes with 1009
     */
    public static function upgrade(ClientRequest $request, array $headers, callable $callback, int $maxMessage = self::MAX_MESSAGE): void
    {
        $request->sendResponseHeaders(101, $headers);
        self::run($request, $callback, self::subprotocolOf($headers), $maxMessage);
    }

    /**
     * For adapters whose own code already sent the `101` (such as swerve-psr15's bridge, after its
     * middleware decided the headers): run `$callback` on `$connection` as {@see WebSocket::upgrade()}
     * does, with the same close codes and cancellation. `$connection` need be no more than a
     * {@see Duplex}: read(), write(), end() and close() are all this uses.
     *
     * @param callable(WebSocket): void $callback
     * @param int                       $maxMessage see upgrade()
     */
    public static function run(Duplex $connection, callable $callback, ?string $subprotocol = null, int $maxMessage = self::MAX_MESSAGE): void
    {
        self::open($connection, $subprotocol, $maxMessage)->pump($callback(...));
    }

    /** The decision for the request of an exchange. */
    private static function handshakeOf(ClientRequest $request, array $subprotocols, ?array $origins): WebSocketHandshake
    {
        return self::handshake($request->getMethod(), $request->getProtocolVersion(), $request->getRequestHeaders(), !$request->eof(), $subprotocols, $origins);
    }

    /** The subprotocol a `101`'s headers carry, however they capitalize its name. */
    private static function subprotocolOf(array $headers): ?string
    {
        $subprotocol = \array_change_key_case($headers)['sec-websocket-protocol'] ?? null;

        return \is_array($subprotocol) ? $subprotocol[0] : $subprotocol;
    }

    /** Register the connection and start the ping loop; the `101` is already on its way. */
    private static function open(Duplex $connection, ?string $subprotocol, int $maxMessage): self
    {
        $ws = new self($connection, $subprotocol, $maxMessage);
        self::$open[\spl_object_id($ws)] = \WeakReference::create($ws);
        self::keepAlive();

        return $ws;
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
        if (!$this->pumped) {
            $message = $this->readMessage();
            if (null === $message) {
                return null;
            }
            [$data, $this->binary] = $message;

            return $data;
        }
        while (!$this->inbox && !$this->readerDone) {
            phasync::awaitFlag($this);
        }
        if (!$this->inbox) {
            return null;
        }
        [$data, $this->binary] = \array_shift($this->inbox);
        phasync::raiseFlag($this); // the pump may wait for room

        return $data;
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
     * May be called from any coroutine; messages from different coroutines never interleave.
     * Waits while the client reads slowly, and gives the client up (closing the connection)
     * after {@see WebSocket::WRITE_TIMEOUT} seconds.
     *
     * ```php
     * $ws->send(json_encode(['type' => 'welcome']));
     * ```
     *
     * @param string $text the message, which must be UTF-8
     *
     * @throws \ValueError when `$text` is not valid UTF-8
     *
     * @see WebSocket::sendBinary
     */
    public function send(string $text): void
    {
        if (!\preg_match('//u', $text)) {
            throw new \ValueError('A WebSocket text message must be UTF-8: use sendBinary()');
        }
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
     * Close the connection: send a close frame with `$code` and `$reason` and end the sending side, once.
     *
     * `receive()` returns null from then on, and `$onClose` fires once, with the code and reason of
     * whoever said goodbye first. The connection is closed when the client closes its side, or
     * after a moment without. Returning from the callback does the same with 1000. May be called
     * from any coroutine; later calls do nothing.
     *
     * ```php
     * $ws->onMessage->listen(function (string $data) use ($ws) {
     *     if ('bye' === $data) {
     *         $ws->end(1000, 'bye');
     *     }
     * });
     * ```
     *
     * @param int    $code   the close code (RFC 6455, section 7.4): 1000 is a normal closure; 1005, 1006 and the other codes that never go on the wire are refused
     * @param string $reason the close frame's reason text: UTF-8, at most 123 bytes
     *
     * @throws \ValueError when the code may not be sent or the reason is too long or not UTF-8
     */
    public function end(int $code = 1000, string $reason = ''): void
    {
        if (!self::validCode($code) || \strlen($reason) > 123 || !\preg_match('//u', $reason)) {
            throw new \ValueError("Invalid WebSocket close code or reason: $code");
        }
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->note($code, $reason);
        unset(self::$open[\spl_object_id($this)]);
        if (!self::$open && null !== self::$idle) {
            phasync::raiseFlag(self::$idle); // the ping loop ends now, not after its sleep
        }
        if (!$this->dead) {
            $this->put(self::head(8, 2 + \strlen($reason)) . \pack('n', $code) . $reason, self::CLOSE_TIMEOUT);
            $this->connection->end();
        }
        if (null !== $this->reading && $this->reading !== \Fiber::getCurrent()) {
            try {
                phasync::throw($this->reading, new CancelledException('The WebSocket was ended')); // from the wait for the client's bytes
            } catch (\LogicException) {
                // $this->reading already has an exception on its way: a drain cancelled the
                // same wait at the same moment. Nothing more to do; need()'s catch sees it.
            }
        }
        phasync::raiseFlag($this); // a reader that waits for room in the inbox
        $this->onClose->trigger($this->code, $this->reason);
    }

    /**
     * Whether the connection is closed: `end()` was called, the client said goodbye, or the connection ended.
     *
     * @see WebSocket::end
     */
    public function isClosed(): bool
    {
        return $this->ended || $this->dead;
    }

    /**
     * Read the connection while the callback runs in a coroutine of its own.
     *
     * Messages go to the `$onMessage` listeners, or to the inbox for receive() when there are none; pings are
     * answered, and when the connection ends (the client left or closed, or swerve drains) the
     * callback is cancelled if it still runs. So a callback that only sends, such as one
     * forwarding a subscription, ends when its client is gone.
     */
    private function pump(\Closure $callback): void
    {
        $this->pumped = true;
        $app          = phasync::go(function () use ($callback) {
            try {
                $callback($this);
                $this->end(1000);
            } catch (CancelledException $e) {
                if (!$this->readerDone) {
                    $this->end(1011);
                    throw $e;
                }
                // The connection ended, and the pump stopped the callback: nothing went wrong
            } catch (\Throwable $e) {
                $this->end(1011);
                throw $e;
            } finally {
                phasync::raiseFlag($this); // the pump may wait for room nobody will make now
            }
        });
        $failure = null;
        try {
            while (null !== ($message = $this->readMessage())) {
                if ($this->onMessage->hasListeners()) {
                    try {
                        $this->onMessage->trigger($message[0], $message[1]);
                    } catch (\Throwable $e) {
                        $this->end(1011);

                        throw $e;
                    }

                    continue;
                }
                while (\count($this->inbox) >= self::INBOX && !$this->ended && !$app->isTerminated()) {
                    phasync::awaitFlag($this); // the client sends faster than the callback receives
                }
                $this->inbox[] = $message;
                phasync::raiseFlag($this);
            }
        } catch (\Throwable $e) {
            $failure = $e;
        }
        $this->readerDone = true;
        phasync::raiseFlag($this);
        if (!$app->isTerminated()) {
            phasync::cancel($app);
        }
        if (null !== $failure) {
            throw $failure;
        }
        phasync::await($app); // its exception, if any, for swerve's log
    }

    /**
     * The next message from the client, as [message, binary], or null once the connection is
     * closed, after answering what the protocol asks for: pings, the close handshake, and an
     * error of the client's with its close code.
     *
     * @return array{0: string, 1: bool}|null
     */
    private function readMessage(): ?array
    {
        $message = null; // a fragmented message in progress
        $text    = false;
        while (!$this->ended) {
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
                    (\ord($head[0]) & 0x70)                                     // reserved bits, no extension agreed
                    || !(\ord($head[1]) & 0x80)                                 // clients must mask
                    || $length < 0                                              // a 64-bit length has its top bit clear
                    || ($control ? !$fin || $length > 125 || $opcode > 10       // control frames: whole and short
                        : $opcode > 2 || (0 === $opcode) !== (null !== $message)) // a continuation continues something
                ) {
                    $this->end(1002);

                    return null;
                }
                if ($length > $this->maxMessage - \strlen($message ?? '')) {
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
                $code   = $length >= 2 ? \unpack('n', $payload)[1] : 1005;
                $reason = \substr($payload, 2);
                if (1 === $length || ($length >= 2 && !self::validCode($code))) {
                    $this->end(1002);
                } elseif (!\preg_match('//u', $reason)) {
                    $this->end(1007);
                } else {
                    $this->note($code, $reason);
                    $this->end(1005 === $code ? 1000 : $code);
                }

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

    /** $n bytes from the client; UnderflowException when its side ended, or end() was called meanwhile. */
    private function need(int $n): string
    {
        while (\strlen($this->buffer) < $n) {
            $this->reading = \Fiber::getCurrent();
            try {
                $bytes = $this->connection->read(65536);
            } catch (IOException) {
                $bytes = ''; // reset
            } catch (CancelledException $e) {
                if (!$this->ended) {
                    throw $e; // not end()'s
                }
                $bytes = '';
            } finally {
                $this->reading = null;
            }
            if ('' === $bytes) {
                throw new \UnderflowException("The client's side ended");
            }
            $this->buffer .= $bytes;
        }
        $bytes        = \substr($this->buffer, 0, $n);
        $this->buffer = \substr($this->buffer, $n);

        return $bytes;
    }

    /** Remember how the connection ended, if nobody did yet; 1005 is "no code", 1006 "no goodbye". */
    private function note(int $code, string $reason): void
    {
        if (null === $this->code) {
            $this->code   = $code;
            $this->reason = $reason;
        }
    }

    /** The close codes that may be on the wire (RFC 6455, 7.4.1, and the registry's 1012-1014). */
    private static function validCode(int $code): bool
    {
        return ($code >= 1000 && $code <= 1003) || ($code >= 1007 && $code <= 1014) || ($code >= 3000 && $code <= 4999);
    }

    /** The head of a frame that is final and not masked. */
    private static function head(int $opcode, int $length): string
    {
        return \chr(0x80 | $opcode) . ($length < 126 ? \chr($length) : ($length < 65536 ? \chr(126) . \pack('n', $length) : \chr(127) . \pack('J', $length)));
    }

    private function frame(int $opcode, string $payload): void
    {
        if (!$this->ended && !$this->dead) {
            $this->put(self::head($opcode, \strlen($payload)) . $payload, self::WRITE_TIMEOUT);
        }
    }

    /** Write whole frames; a client that is gone, or that doesn't read, is given up. */
    private function put(string $bytes, float $timeout): void
    {
        try {
            $this->connection->write($bytes, $timeout);
        } catch (IOException) {
            $this->dead = true;
        } catch (TimeoutException) {
            $this->dead = true;
            $this->connection->close(); // the frame may be half sent
        }
    }

    /**
     * Ping every open WebSocket of this process every $pingInterval seconds, from one coroutine
     * for all of them, which ends when none is open. A ping doesn't wait for a client that reads
     * slowly: that one's own sends time out and give it up.
     */
    private static function keepAlive(): void
    {
        if (self::$pinging) {
            return;
        }
        self::$pinging = true;
        // A service: it serves every connection, and no request waits for it (a drain waits for requests)
        phasync::service(static function () {
            try {
                while (self::$open) {
                    try {
                        phasync::awaitFlag(self::$idle ??= new \stdClass(), self::$pingInterval);
                    } catch (TimeoutException) {
                        foreach (self::$open as $id => $reference) {
                            $ws = $reference->get();
                            if (null === $ws) {
                                unset(self::$open[$id]); // dropped without end()

                                continue;
                            }
                            try {
                                $ws->connection->write("\x89\x00", 0.0); // a ping with no payload
                            } catch (IOException|TimeoutException) {
                                // gone, or not reading: the connection finds out by itself
                            }
                        }
                    }
                }
            } finally {
                self::$pinging = false;
            }
        });
    }

    /** A refusal: the complete final response. */
    private static function refusal(int $status, string $body, array $headers): WebSocketHandshake
    {
        return new WebSocketHandshake($status, $headers + ['content-type' => 'text/plain', 'content-length' => (string) \strlen($body)], $body);
    }

    /** Answer a request that is no handshake, as an ordinary final response. */
    private static function refuse(ClientRequest $request, WebSocketHandshake $refusal): void
    {
        $request->sendResponseHeaders($refusal->status, $refusal->headers);
        $request->write($refusal->body);
    }
}
