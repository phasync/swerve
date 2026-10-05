<?php

namespace Swerve;

use phasync\IOException;

/**
 * A Server-Sent Events stream (`EventSource` in the browser) on a {@see ClientRequest}.
 *
 * Creating it sends the head (`200`, `content-type: text/event-stream`, `cache-control: no-cache`)
 * and puts it on the wire at once, so the browser sees the stream is open before the first event
 * exists. The body is chunked on HTTP/1.1 and ends with the connection on HTTP/1.0; each event is
 * written as one chunk. The stream ends when the handler returns.
 *
 * ```php
 * return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
 *     $sse = new Swerve\ServerSentEvents($request);
 *     foreach (Swerve::subscribe('news', $sse->lastEventId()) as $message) {
 *         $sse->send($message);
 *     }
 * });
 * ```
 *
 * A client that leaves makes the next `send()` or `comment()` throw `phasync\IOException`. Let it
 * leave the handler: swerve ends the exchange without logging, so a loop ends with its client. A
 * handler that may wait long between events sends a comment now and then, to notice a departure
 * and to keep proxies from closing the idle stream.
 *
 * @see docs/websocket.md
 */
final class ServerSentEvents
{
    private readonly ?string $lastEventId;
    private readonly bool $head;

    /**
     * Send the head of the stream.
     *
     * A `HEAD` request gets the head and an empty body, and `send()` and `comment()` throw
     * `IOException`: there is no stream.
     *
     * @param ClientRequest                      $request the exchange to stream on
     * @param array<string, string|list<string>> $headers more response headers, such as `access-control-allow-origin`
     *
     * @throws HeadersSentException when the request has a response head already
     */
    public function __construct(private readonly ClientRequest $request, array $headers = [])
    {
        $this->lastEventId = $request->getRequestHeaders()['last-event-id'][0] ?? null;
        $this->head        = 'HEAD' === $request->getMethod();
        $request->sendResponseHeaders(200, ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache', 'x-accel-buffering' => 'no'] + \array_change_key_case($headers));
        $request->flush();
        if ($this->head) {
            $request->end();
        }
    }

    /**
     * The `Last-Event-ID` the client sent when it reconnected: the `id` of the last event it saw
     * before the connection dropped, or null at its first connection.
     */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Send one event.
     *
     * `$data` may hold any line endings: each line becomes a `data:` field, which the browser
     * joins with "\n" again. Line breaks (and NUL in `$id`) are removed from `$event` and `$id`,
     * so they cannot inject a field.
     *
     * ```php
     * $sse->send(json_encode($order), event: 'order', id: (string) $order->id);
     * ```
     *
     * @param string      $data  the event's data
     * @param string|null $event the event's name: the browser's `addEventListener($event, ...)`, `message` when null
     * @param string|null $id    sets the browser's last event id, which it sends back as `Last-Event-ID` when it reconnects
     * @param int|null    $retry milliseconds the browser waits before it reconnects
     *
     * @throws IOException when the client is gone
     */
    public function send(string $data, ?string $event = null, ?string $id = null, ?int $retry = null): void
    {
        $frame = '';
        if (null !== $event) {
            $frame .= 'event: ' . \str_replace(["\r", "\n"], '', $event) . "\n";
        }
        if (null !== $id) {
            $frame .= 'id: ' . \str_replace(["\r", "\n", "\0"], '', $id) . "\n";
        }
        if (null !== $retry) {
            $frame .= "retry: $retry\n";
        }
        foreach (\preg_split('/\r\n|\r|\n/', $data) as $line) {
            $frame .= "data: $line\n";
        }
        $this->put("$frame\n");
    }

    /**
     * Send a comment: the browser ignores it, so it keeps the stream alive through proxies and
     * lets a handler find out that the client is gone.
     *
     * @param string $text what the comment says; line breaks become spaces
     *
     * @throws IOException when the client is gone
     */
    public function comment(string $text = ''): void
    {
        $this->put(': ' . \preg_replace('/\r\n|\r|\n/', ' ', $text) . "\n\n");
    }

    private function put(string $bytes): void
    {
        if ($this->head) {
            throw new IOException('There is no event stream to send to');
        }
        $this->request->write($bytes);
    }
}
