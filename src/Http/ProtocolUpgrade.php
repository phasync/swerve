<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Swerve\Http\Message\Response;

/**
 * A connection that switches from HTTP to another protocol (RFC 9110, section 7.8): the base
 * of WebSocket, and of any protocol of your own.
 *
 *     return WebSocket::from($request, function (WebSocket $ws) {
 *         foreach ($ws as $message) {
 *             $ws->send("echo: $message");
 *         }
 *     });
 *
 * from() answers the request: with 101 Switching Protocols when the subclass accepts the
 * handshake, or with the subclass's refusal. After the 101, the callback runs in a coroutine of
 * its own, and the connection closes when it returns.
 *
 * A subclass implements handshake(), and speaks its protocol with read(), write() and end(); it
 * may override run() to do work around the callback. Application code sees only the subclass's
 * API. The bytes travel on swerve's two streams: the request body is what the client sends after
 * the handshake, the response body what goes back.
 */
abstract class ProtocolUpgrade
{
    /** How long write() waits for a client that stopped reading before giving it up. */
    public const WRITE_TIMEOUT = 30.0;

    private readonly UpgradeStream $out;
    private bool $writable = true;

    final protected function __construct(private readonly StreamInterface $in)
    {
        $this->out = new UpgradeStream();
    }

    /**
     * The response to $request: 101, with $callback run on the connection after it, or the
     * subclass's refusal.
     *
     * @param \Closure(static): void $callback
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

        return new Response($connection->out, ['Connection' => 'Upgrade'] + $headers, 101);
    }

    /**
     * Check the request: the headers of the 101 (with `Upgrade` naming the protocol), or the
     * response that refuses it.
     *
     * @return array<string, string>|ResponseInterface
     */
    abstract protected function handshake(ServerRequestInterface $request): array|ResponseInterface;

    /**
     * Run the application's callback on the connection. The connection ends when this returns.
     *
     * @param \Closure(static): void $callback
     */
    protected function run(\Closure $callback): void
    {
        $callback($this);
    }

    /**
     * Up to $length bytes from the client, waiting for as long as it is quiet. '' when its side
     * has ended: it closed, the connection broke, or swerve drains (a shutdown or reload).
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
     * Send bytes. False when they can't go: the output has ended, or the client stopped reading
     * for WRITE_TIMEOUT seconds, which ends the connection at once.
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
            $this->end();

            return false;
        }
    }

    /**
     * No more bytes to send: the connection closes once those written are sent, after reading
     * the client's last bytes for a moment so that a goodbye arrives.
     */
    protected function end(): void
    {
        if ($this->writable) {
            $this->writable = false;
            $this->out->end();
        }
    }
}
