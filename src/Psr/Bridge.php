<?php

namespace Swerve\Psr;

use phasync\IOException;
use phasync\Psr\ServerRequest;
use phasync\Psr\Uri;
use phasync\ShutdownException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Swerve\ClientRequest;
use Swerve\EventStreamResponse;
use Swerve\ServerSentEvents;
use Swerve\WebSocketResponse;

/**
 * Turns a Swerve {@see ClientRequest} into a PSR-7 request for a PSR-15 handler, and the PSR-7
 * response it returns into the ClientRequest's response.
 *
 * Both bodies stream. The request body is a lazy stream on the connection: nothing is read until
 * the application reads, and `Expect: 100-continue` is answered by that first read. A POST form
 * (`application/x-www-form-urlencoded` or `multipart/form-data`) is parsed into `getParsedBody()`
 * and `getUploadedFiles()` when asked for, as PHP fills `$_POST` and `$_FILES`; any other body is
 * left raw. The response body is read in chunks and written as it is read, so a body larger than
 * memory is fine as long as its stream produces it piecemeal. The response has a Content-Length
 * when the application declared one or its body knows its size, and is otherwise chunked.
 *
 * A `101` response ({@see WebSocketResponse}) is sent as a head, then its body pumped until eof: the
 * body is the outbound WebSocket frames, which a middleware may wrap to see every one, and reading it
 * is what starts the handler (lazily, on its first byte). An {@see EventStreamResponse} is not written
 * as a body at all: the exchange becomes a stream of events, served by swerve's own codec.
 *
 * An exception from the handler is swerve's to deal with: a 500 when the head was not sent yet,
 * an aborted connection after, logged either way.
 *
 * @internal
 */
final class Bridge
{
    private const CHUNK = 65536;

    /** @var \WeakMap<\Fiber, true>|null the coroutines sending a `101`'s body */
    private static ?\WeakMap $upgrading = null;

    private function __construct()
    {
    }

    /**
     * Serve an exchange with a PSR-7 handler: the request built from it, the response sent.
     *
     * @param \Closure(ServerRequestInterface): ResponseInterface $handle
     */
    public static function serve(ClientRequest $client, \Closure $handle): void
    {
        $response = $handle(self::request($client));
        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException('The PSR-7 handler returned ' . \get_debug_type($response) . ', not a Psr\\Http\\Message\\ResponseInterface');
        }
        self::send($client, $response);
    }

    /**
     * The PSR-7 server request for an exchange; its body is not read. Its server parameters are
     * the request's `$_SERVER`.
     */
    private static function request(ClientRequest $client): ServerRequestInterface
    {
        $method  = $client->getMethod();
        $target  = $client->getTarget();
        $headers = $client->getRequestHeaders();
        $server  = $_SERVER; // the request's, filled by the Dispatcher before any suspension

        // A request has a body when it says so; its length is known unless it is chunked
        $size    = isset($headers['transfer-encoding']) ? null : (int) ($headers['content-length'][0] ?? 0);
        $body    = new RequestBody($client, $size);
        $form    = isset($headers['content-type']) ? FormBody::for($method, $headers['content-type'][0], $body) : null;
        $cookies = isset($headers['cookie']) ? ServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];

        $request = new ServerRequest(
            $method,
            $target,
            $form ? $form->input(...) : $body,
            $headers,
            null,
            $server,
            $cookies,
            $form ? $form->files(...) : [],
            $form ? $form->fields(...) : null,
            [],
            $client->getProtocolVersion(),
        );
        // Request::getUri() derives the URI from the request target plus the Host header, which
        // is wrong for the absolute form (a proxy request, RFC 9112 3.2.2): $target is already
        // the whole URI there, not a path to append one to. HttpConnection already made the Host
        // header this request's authority (see its own absolute-form handling); withUri() here
        // only fixes getUri() to match, not getRequestTarget() (origin-form once a URI is
        // attached, same as any other withUri() call). The target's own scheme is what the client
        // asked the origin to be reached by, not what this connection actually is; a plain
        // connection reports its own scheme regardless, same as any other request's URI.
        if ('/' !== ($target[0] ?? '') && '*' !== $target) {
            $request = $request->withUri((new Uri($target))->withScheme($client->getScheme()));
        }

        return $request;
    }

    /**
     * Send a PSR-7 response: the head, the body in chunks as the stream yields them, and the end.
     */
    private static function send(ClientRequest $client, ResponseInterface $response): void
    {
        if ($response instanceof EventStreamResponse) {
            // A HEAD gets the head and no body: the callback is not run, so an endpoint's work is
            // not done for a client that asked for no answer to read. (ServerSentEvents itself
            // handles a HEAD too, for those who build it directly.)
            $sse = new ServerSentEvents($client, $response->getHeaders());
            if ('HEAD' !== $client->getMethod()) {
                ($response->callback)($sse);
            }

            return;
        }
        if (101 === $response->getStatusCode()) {
            $client->sendResponseHeaders(101, $response->getHeaders());
            $client->flush();
            self::pumpUpgrade($client, $response->getBody());
            if (!$client->isClosed()) {
                $client->end(); // the handler may have closed the connection, in which case there is nothing left to end
            }

            return;
        }
        $body    = $response->getBody();
        $headers = $response->getHeaders();
        $size    = $body->getSize();
        if (null !== $size && $size > 0 && !$response->hasHeader('Content-Length')) {
            $headers['Content-Length'] = (string) $size;
        }
        $client->sendResponseHeaders($response->getStatusCode(), $headers);
        try {
            if ('HEAD' !== $client->getMethod()) {
                if ($body->isSeekable()) {
                    $body->rewind(); // a response is built by writing to its body, which leaves it at the end
                }
                while (!$body->eof()) {
                    $chunk = $body->read(self::CHUNK);
                    if ('' !== $chunk) {
                        $client->write($chunk);
                    }
                }
            }
            $client->end();
        } finally {
            // However the response ended -- whole, or with its client gone mid-stream, which fails
            // the write -- the application's producer of the body is told so: one blocked on an
            // append nobody will ever read again would outlive the connection, and the request
            $body->close();
        }
    }

    /**
     * Write a `101`'s body (the outbound frames) to the client until it ends; a write that fails
     * closes the body, so the handler's own writes fail and it winds down, instead of propagating.
     */
    /**
     * Whether the current coroutine is sending a `101`'s body: only then does a {@see WebSocketBody} start its handler.
     *
     * @internal
     */
    public static function upgrading(): bool
    {
        $fiber = \Fiber::getCurrent();

        return null !== $fiber && isset(self::$upgrading[$fiber]);
    }

    /**
     * How many `101` bodies are being pumped: the upgraded exchanges still live, which a
     * lingering lifecycle waits for ({@see \Swerve\Swerve::drainConnections()}).
     *
     * @internal
     */
    public static function upgrades(): int
    {
        return \count(self::$upgrading ?? []);
    }

    private static function pumpUpgrade(ClientRequest $client, StreamInterface $body): void
    {
        $fiber = \Fiber::getCurrent();
        self::$upgrading ??= new \WeakMap();
        self::$upgrading[$fiber] = true;
        if ($body instanceof TunnelBody) {
            // A handler that closes its connection means now: a response write stalled on a client
            // that stopped reading would otherwise hold the connection, and this coroutine, until
            // the client gives up (a WebSocket gives itself up, see WebSocket::put()'s timeout)
            $body->onHandlerClose(static function () use ($client): void {
                $client->close();
            });
        }
        $pump                    = static function () use ($client, $body) {
            do {
                $chunk = $body->read(self::CHUNK);
                if ('' !== $chunk) {
                    $client->write($chunk);
                }
            } while (!$body->eof());
        };
        try {
            $pump();
        } catch (IOException) {
            $body->close();
        } catch (ShutdownException $e) {
            // The worker stops: the WebSocket says goodbye (1001, or 1012 on a reload), shielded, and
            // ends the body; what it wrote goes out before the exchange ends
            try {
                \phasync::shielded($pump);
            } catch (IOException) {
                $body->close();
            }
            throw $e;
        } finally {
            unset(self::$upgrading[$fiber]);
            \Swerve\Swerve::connectionEnded();
        }
    }
}
