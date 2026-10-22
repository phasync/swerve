<?php

namespace Swerve;

use phasync\Psr\Response;
use Swerve\ServerSentEvents;

/**
 * A PSR-7 response that asks the adapter to stream Server-Sent Events.
 *
 * A PSR-15 handler (a Slim route, or a middleware stack around it) returns it like any response;
 * {@see Bridge} then sends the head of the event stream and runs the callback, which writes events
 * with swerve's {@see ServerSentEvents}. The stream ends when the callback returns.
 *
 * ```php
 * $app->get('/news', function ($request, $response) {
 *     return EventStreamResponse::stream(function (ServerSentEvents $sse) {
 *         foreach (Swerve::subscribe('news', heartbeat: 15) as $message) {
 *             null === $message ? $sse->comment('keep-alive') : $sse->send($message, event: 'news');
 *         }
 *     });
 * });
 * ```
 *
 * It is an ordinary immutable response, with status 200: middleware may `with*()` it and the clones
 * keep the callback. The headers of the response that reaches the adapter, which are the ones given
 * to {@see EventStreamResponse::stream()} and those middleware added (CORS, caching), are sent with
 * the head. The status is always 200, and `content-type`, `cache-control` and `x-accel-buffering`
 * are swerve's: a response header of those names is ignored. The body of the response is not used.
 *
 * A `HEAD` request gets the head and an empty body, and the callback is not called: there is no
 * stream to send to.
 *
 * @see WebSocketResponse
 */
final class EventStreamResponse extends Response
{
    /**
     * @param \Closure(ServerSentEvents): void   $callback
     * @param array<string, string|list<string>> $headers
     */
    private function __construct(public readonly \Closure $callback, array $headers)
    {
        parent::__construct(200, $headers);
    }

    /**
     * A response that streams events written by `$callback`, which runs after the handler returned.
     *
     * The callback returns to end the stream. A write to a client that left throws
     * `phasync\IOException`: let it leave the callback, as swerve's {@see ServerSentEvents} documents,
     * so a loop ends with its client. Any other exception is logged and aborts the connection.
     *
     * @param callable(ServerSentEvents): void   $callback
     * @param array<string, string|list<string>> $headers  more response headers, such as `access-control-allow-origin`
     */
    public static function stream(callable $callback, array $headers = []): self
    {
        return new self($callback(...), $headers);
    }
}
