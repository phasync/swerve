# Realtime: Server-Sent Events and WebSockets

A request in swerve may stay open as long as it likes: the worker serves other requests
meanwhile, since a waiting coroutine costs little (tens of kilobytes). That makes pushing to
the browser simple. Swerve gives every request two streams, and protocols are written on
them; `Swerve\Http\WebSocket` is one, written that way:

- the **request body**: what the client sends, read as it arrives;
- the **response body**: what goes back, sent as it is written.

Both examples in [`examples/`](../examples) are complete chat rooms, one per technique, and
swerve's tests run them.

## Streaming a response: UnbufferedStream

`phasync\Psr\UnbufferedStream` is a response body you write to while swerve sends it:

```php
use phasync\Psr\UnbufferedStream;

$out = new UnbufferedStream(1, 60);         // buffer size in bytes, timeout in seconds
phasync::go(function () use ($out) {        // write from a coroutine of its own
    $out->append("some data\n");            // waits while more than the buffer size is unsent
    $out->end();                            // the response ends
});

return new Response(200, ['Content-Type' => 'text/plain'], $out);
```

- `append()` returns once the stream holds at most its buffer size: with a buffer of 1 byte, it
  returns when swerve has taken the data. If swerve doesn't take it within the timeout (the
  client stopped reading, or left), `append()` throws `phasync\TimeoutException`. That is how a
  producer learns its client is gone.
- The same timeout applies the other way: swerve waits at most that long for the next data.
  A stream that stays quiet longer (no event, no message to send) ends the response with a
  `TimeoutException`. Send something before then: a keep-alive comment for Server-Sent Events,
  a ping for a WebSocket (the example pings every 15 s).
- `end()` ends the response. Call it exactly once, in a `finally`.
- Create the stream in the handler, write to it from a coroutine started there, and return the
  response: swerve starts sending as soon as the handler returns.

## Server-Sent Events

The browser's `EventSource` reads a `text/event-stream` response and reconnects by itself
when it ends. From [`examples/sse-chat/swerve.php`](../examples/sse-chat/swerve.php):

```php
private function events(): ResponseInterface
{
    // Before returning: nothing is missed. A null every 15 s without a message; the loop
    // ends when the worker drains
    $subscription = Swerve::subscribe('chat', heartbeat: 15);
    $out          = new UnbufferedStream(1, 60);
    phasync::go(static function () use ($subscription, $out) {
        try {
            $out->append("retry: 1000\n\n");
            foreach ($subscription as $message) {
                $out->append(null === $message ? ": keep-alive\n\n" : "data: $message\n\n");
            }
        } catch (TimeoutException) {
            // The client left
        } catch (SubscriberLagException) {
            // Too far behind: the browser's EventSource reconnects
        } finally {
            $out->end();
        }
    });

    return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $out);
}
```

- The keep-alive comment every 15 s keeps proxies from closing an idle connection, and makes
  the producer notice a client that left: at the next write (within 15 s) when the client
  closed its connection, and within about 75 s (15 s, plus the 60 s timeout) when it vanished
  without closing it (a network gone). Until then its subscription holds on to the messages it
  did not take.
- Messages go the other way as ordinary requests: `POST /messages` in the example.
- An SSE event is `data: <one line>\n\n`; JSON-encode messages so they stay on one line.
  `id:` and `event:` fields work as the SSE standard says; swerve has no message history to
  resume from after a `Last-Event-ID`, so a reconnecting client catches up from your storage.
- **On a reload or shutdown**, the worker drains: every subscription's loop ends, so a
  producer like the one above ends its response at once, and the browser reconnects to another
  worker. A long response that is not fed by a subscription (long polling, a slow download)
  runs until the drain deadline, a second before `--grace` (30 s); check `Swerve::draining()`
  in its loop to end it sooner.

## WebSockets

A WebSocket starts as an HTTP request with `Upgrade: websocket`. When the application answers
`101 Switching Protocols`, swerve sends that head at once, and from then on the request body is
everything the client sends and the response body everything that goes back, raw: no
HTTP framing, no HTTP timeouts, no size limit.

`Swerve\Http\WebSocket` speaks the protocol (RFC 6455): the handshake, framing, fragmented
messages, ping and pong, close codes, UTF-8 checks, and a limit on message size (1 MiB). The
callback runs in a coroutine of its own after the 101, and the connection closes when it
returns (1000) or throws (1011, logged). One coroutine receives; any may send. Swerve pings
every 15 s, so proxies keep a quiet connection open. The chat example:

```php
use Swerve\Http\WebSocket;

return WebSocket::from($request, static function (WebSocket $ws) {
    $subscription = Swerve::subscribe('chat');
    $forward      = phasync::go(static function () use ($ws, $subscription) {
        try {
            foreach ($subscription as $message) {
                $ws->send($message);
            }
        } catch (SubscriberLagException) {
            $ws->close(1008);
        } catch (CancelledException) {
        }
    });

    foreach ($ws as $message) {                        // ends when the connection closes
        Swerve::publish('chat', $message);             // validate it first, see the example
    }

    if (!$forward->isTerminated()) {
        phasync::cancel($forward);                     // stops forwarding; ends the subscription
    }
});
```

Make the callback `static` when you write it in a controller method: a plain closure keeps
`$this`, and with it the controller and often the whole application, alive for as long as the
socket is open (measured in a Laminas controller: 380 KiB a socket, against 84 KiB).

A callback that only sends, such as a loop over a subscription, ends when its client leaves:
the connection is read all the time, and the callback is cancelled when it closes.

`$ws->receive()` returns the next message, or null once closed; `isBinary()` tells a binary
message from text, `sendBinary()` sends one, and `close($code)` says goodbye.

For another protocol, extend `Swerve\Http\ProtocolUpgrade`, the base of `WebSocket`: implement
`handshake()` (the 101's headers, or a refusal), and speak the protocol with its `read()`,
`write()` and `end()`; `YourProtocol::from($request, $callback)` then works the same way.

How the two streams behave, for writing a protocol directly on them:

- **Answering 101**: set `Upgrade` and `Connection: Upgrade` yourself; swerve sends the
  headers as given. The response body's `read()` should wait for data (an `UnbufferedStream`
  does). When the response body ends, swerve closes the connection, reading the client's last
  bytes for up to 2 s so that your goodbye (a close frame) arrives.
- **The request body ends** (`read()` returns `''`) when the client closes its side, when the
  connection breaks, or when swerve drains (a shutdown or reload). Then say goodbye in your
  protocol (a WebSocket close with 1001, "going away") and end the response body.
- **A client that stops reading**: `append()` on the response body throws
  `TimeoutException` after its timeout. Calling `$request->getBody()->close()` ends the
  connection at once.
- **Don't read an upgrade request's body inside the handler** before returning the 101:
  swerve can't know yet whether the body ends at its HTTP framing or goes on raw, so it
  declines the upgrade (the read returns `''`, and a 101 becomes a 500). Read it from the
  coroutine you start, as the example does. Middleware that parses request bodies must leave
  upgrade requests alone for the same reason.
- **Many clients**: every open WebSocket is a connection of one worker. Without phasync-ext a
  worker holds about 960 connections; see [Production](production.md#sizing).

## Choosing

| | Server-Sent Events | WebSockets |
|---|---|---|
| Direction | server to browser; the browser sends with ordinary requests | both ways, one connection |
| Browser API | `EventSource`, reconnects by itself | `WebSocket`; reconnecting is yours |
| Through proxies | ordinary HTTP; proxy buffering must be off | needs the proxy's WebSocket support |
| Server code | an `UnbufferedStream` | a protocol implementation (the example's) |

Both push a message to thousands of clients as fast as the workers can write, and both use
[publish and subscribe](publish-subscribe.md) to reach clients on every worker.

Next: [Publish and subscribe](publish-subscribe.md).
