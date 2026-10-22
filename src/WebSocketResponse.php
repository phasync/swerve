<?php

namespace Swerve;

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Psr\WebSocketBody;

/**
 * The PSR-7 response that upgrades a request to a WebSocket: the entry of the adapter, {@see WebSocketResponse::from()}.
 *
 * The handler of the application is always given swerve's own {@see WebSocket}, the same class in
 * every framework adapter, so the examples in swerve's `docs/websocket.md` apply as they are. The
 * codec, the close codes and the limits are swerve's too.
 *
 * ```php
 * $app->get('/chat', function ($request, $response) {
 *     $user = $request->getAttribute('user');   // read the request now: the handler runs after the route returned
 *
 *     return WebSocketResponse::from($request, function (WebSocket $ws) use ($user) {
 *         foreach ($ws as $message) {
 *             $ws->send("$user: $message");
 *         }
 *     });
 * });
 * ```
 *
 * `from()` checks the handshake in the request, with swerve's own decision ({@see WebSocket::handshake()}),
 * and returns an ordinary PSR-7 response either way. A request that is no handshake gets the refusal
 * swerve itself would send (426 for a plain GET, 400 for an invalid handshake, 403 for an origin that is
 * not allowed), a plain response that middleware decorates like any other. A handshake gets a `101`
 * response with `Upgrade`, `Connection`, `Sec-WebSocket-Accept` and the chosen `Sec-WebSocket-Protocol`:
 * middleware may add headers to it (CORS, cookies), and the clones it makes keep `$callback` and
 * `$maxMessage`.
 *
 * The `101`'s body is the outbound frames (a middleware may `withBody()` it to inspect them), and the
 * request's body given to `from()` is read for the inbound ones (a middleware that wrapped it with
 * `withBody()` before the route is honoured too). Neither is read, and nothing of phasync's is touched,
 * until {@see Bridge} starts pumping the response body: the handler itself does not run before then, so
 * a middleware that turns the `101` into another response (a `403`, say) is never joined by a handler
 * that started anyway. `from()` itself needs no coroutine: only that first read does.
 *
 * @see EventStreamResponse
 */
final class WebSocketResponse extends Response
{
    /**
     * @param array<string, string>     $headers
     * @param \Closure(WebSocket): void $callback
     */
    private function __construct(array $headers, public readonly \Closure $callback, public readonly int $maxMessage, WebSocketBody $body)
    {
        parent::__construct(101, $headers, $body);
    }

    /**
     * The response to a WebSocket request: a `101` that upgrades it and runs `$handler`, or the refusal.
     *
     * The handler runs after {@see Bridge} starts reading the `101`'s body (lazily, on its first byte),
     * in a coroutine of its own, and the connection closes when it returns (1000) or throws (1011, logged).
     * It is given swerve's `WebSocket`; the other arguments are those of {@see WebSocket::from()}. Take
     * the user or the session from `$request` before returning, and let the handler capture it: the
     * request is not for the handler to keep.
     *
     * @param callable(WebSocket): void $handler
     * @param string[]                  $subprotocols the subprotocols you speak, in your order of preference: the first one the client offered is chosen
     * @param string[]|null             $origins      the allowed `Origin`s (compared case-insensitively), null: any; a client that sends none is let through
     * @param int                       $maxMessage   the largest message received, in bytes; a larger one closes with 1009
     */
    public static function from(ServerRequestInterface $request, callable $handler, array $subprotocols = [], ?array $origins = null, int $maxMessage = WebSocket::MAX_MESSAGE): ResponseInterface
    {
        $handshake = WebSocket::handshake(
            $request->getMethod(),
            $request->getProtocolVersion(),
            \array_change_key_case($request->getHeaders()),
            0 !== $request->getBody()->getSize(),
            $subprotocols,
            $origins,
        );
        if (!$handshake->accepted()) {
            return new Response($handshake->status, $handshake->headers, new StringStream($handshake->body));
        }

        $subprotocol = \array_change_key_case($handshake->headers)['sec-websocket-protocol'] ?? null;
        $subprotocol = \is_array($subprotocol) ? $subprotocol[0] : $subprotocol;
        $callback    = $handler(...);
        $body        = new WebSocketBody($request->getBody(), $callback, $subprotocol, $maxMessage);

        return new self($handshake->headers, $callback, $maxMessage, $body);
    }
}
