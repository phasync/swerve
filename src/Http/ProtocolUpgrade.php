<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use phasync\Psr\Response;

/**
 * A connection that switches from HTTP to another protocol (RFC 9110, section 7.8): the base of {@see WebSocket}, and of any protocol of your own.
 *
 * `from()` answers the request: with 101 Switching Protocols when the subclass accepts the
 * handshake, or with the subclass's refusal. After the 101, the callback runs in a coroutine of
 * its own, and the connection closes when it returns.
 *
 * A subclass implements `handshake()`, and speaks its protocol with `read()`, `write()` and
 * `end()`; it may override `run()` to do work around the callback. Application code sees only
 * the subclass's API. The bytes travel on swerve's two streams: the request body is what the
 * client sends after the handshake, the response body what goes back.
 *
 * ```php
 * return WebSocket::from($request, function (WebSocket $ws) {
 *     foreach ($ws as $message) {
 *         $ws->send("echo: $message");
 *     }
 * });
 * ```
 *
 * A protocol of your own:
 *
 * ```php
 * final class Shout extends ProtocolUpgrade
 * {
 *     protected function handshake(ServerRequestInterface $request): array|ResponseInterface
 *     {
 *         return 'shout' === $request->getHeaderLine('Upgrade')
 *             ? ['Upgrade' => 'shout']
 *             : new Response(426, ['Upgrade' => 'shout'], 'This address speaks shout');
 *     }
 *
 *     public function send(string $line): void
 *     {
 *         $this->write(strtoupper($line) . "\n");
 *     }
 * }
 *
 * return Shout::from($request, function (Shout $connection) {
 *     $connection->send('hello');
 * });
 * ```
 *
 * @see Swerve\Http\WebSocket
 */
abstract class ProtocolUpgrade
{
    /** Seconds write() waits for a client that stopped reading before giving it up. */
    public const WRITE_TIMEOUT = 30.0;

    private readonly UpgradeStream $out;
    private bool $writable = true;

    protected function __construct(private readonly StreamInterface $in)
    {
        $this->out = new UpgradeStream();
    }

    /**
     * The response to `$request`: 101, with `$callback` run on the connection after it, or the subclass's refusal.
     *
     * Return it from the handler. The callback runs in a coroutine of its own once the 101 is on its
     * way.
     *
     * @param ServerRequestInterface $request  the request asking for the upgrade
     * @param \Closure(static): void $callback runs on the connection; the connection closes when it returns
     *
     * @return ResponseInterface the 101 with the connection as its body, or what `handshake()` refused with
     */
    final public static function from(ServerRequestInterface $request, \Closure $callback): ResponseInterface
    {
        $connection = new static($request->getBody());
        $headers    = $connection->handshake($request);
        if ($headers instanceof ResponseInterface) {
            return $headers;
        }
        phasync::go(static function () use ($connection, $callback) {
            try {
                $connection->run($callback);
            } finally {
                $connection->end();
            }
        });

        return new Response(101, ['Connection' => 'Upgrade'] + $headers, $connection->out);
    }

    /**
     * Check the request: the headers of the 101 (with `Upgrade` naming the protocol), or the response that refuses it.
     *
     * @param ServerRequestInterface $request the request asking for the upgrade
     *
     * @return array<string, string>|ResponseInterface
     */
    abstract protected function handshake(ServerRequestInterface $request): array|ResponseInterface;

    /**
     * Run the application's callback on the connection.
     *
     * The connection ends when this returns.
     *
     * @param \Closure(static): void $callback
     */
    protected function run(\Closure $callback): void
    {
        $callback($this);
    }

    /**
     * Up to `$length` bytes from the client, waiting for as long as it is quiet.
     *
     * Returns '' when its side has ended: it closed, the connection broke, or swerve drains (a
     * shutdown or reload).
     *
     * @param int $length the most bytes to return
     */
    protected function read(int $length): string
    {
        try {
            return $this->in->read($length);
        } catch (IOException|HttpError) {
            return '';
        } catch (\RuntimeException $e) {
            // A request body reports a broken connection so, as PSR-7 says read() must
            if (!$e->getPrevious() instanceof IOException) {
                throw $e;
            }

            return '';
        }
    }

    /**
     * Send bytes, waiting while the client reads slowly.
     *
     * Returns false when they can't go: the output has ended, or the client stopped reading for
     * `WRITE_TIMEOUT` seconds, which ends the connection at once.
     *
     * @param string $bytes what to send
     */
    protected function write(string $bytes): bool
    {
        if (!$this->writable) {
            return false;
        }
        try {
            $this->out->append($bytes, static::WRITE_TIMEOUT);

            return true;
        } catch (TimeoutException) {
            $this->in->close();
            self::end(); // not a subclass's end(), which may write

            return false;
        }
    }

    /**
     * Send a few bytes at once, without waiting for a client that reads slowly.
     *
     * For a heartbeat sent to many connections from one coroutine. Returns false once the output
     * has ended.
     *
     * @param string $bytes what to send
     */
    protected function writeNow(string $bytes): bool
    {
        if (!$this->writable) {
            return false;
        }
        $this->out->appendNow($bytes);

        return true;
    }

    /**
     * No more bytes to send.
     *
     * The connection closes once those written are sent, after reading the client's last bytes
     * for a moment so that a goodbye arrives.
     */
    protected function end(): void
    {
        if ($this->writable) {
            $this->writable = false;
            $this->out->end();
        }
    }
}
