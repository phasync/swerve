# Realtime: Server-Sent Events, WebSockets and raw connections

A request in swerve may stay open as long as it likes: the worker serves other requests
meanwhile, since a waiting coroutine costs little (tens of kilobytes). The handler keeps the
`ClientRequest` by not returning, and writes to it whenever it has something to say. The
[chat room in `examples/sse-chat`](../examples/sse-chat) is a complete example, and swerve's
tests run it.

## Server-Sent Events

The browser's `EventSource` reads a `text/event-stream` response and reconnects by itself
when it ends. From [`examples/sse-chat/swerve.php`](../examples/sse-chat/swerve.php):

```php
use phasync\IOException;
use Swerve\ServerSentEvents;

// A null every 15 s without a message, for a keep-alive; the loop ends when the worker
// drains (a shutdown or reload), and the browser reconnects to another
$subscription = Swerve::subscribe('chat', heartbeat: 15);
$sse = new ServerSentEvents($request);   // the head goes out now
try {
    foreach ($subscription as $message) {
        // The keep-alive makes sure a client that left is noticed: this write fails
        null === $message ? $sse->comment('keep-alive') : $sse->send($message);
    }
} catch (IOException) {
    // The client left
} catch (SubscriberLagException) {
    // Too far behind: the browser's EventSource reconnects
}
```

`ServerSentEvents` sends the head and writes a chunk per event; its fields, `Last-Event-ID` and `HEAD`
are in [WebSockets and Server-Sent Events](websocket.md#server-sent-events). Without it, the stream is
`sendResponseHeaders()` with `content-type: text/event-stream` and `write("data: ...\n\n")`.

- Subscribe before sending the head, so that nothing published after it is missed.
- When the client leaves, the next `send()` or `write()` throws `phasync\IOException`: that is how a
  producer learns it can stop. One that stopped reading without closing its connection (a network
  gone) fills its socket buffer, and a write then waits 60 s and throws `phasync\TimeoutException`; a
  keep-alive comment every 15 s makes sure there are writes to fail. Until then its subscription
  holds on to the messages it did not take. An exception thrown out of the handler after the head
  went out aborts the connection and is logged, so catch both as needed.
- Messages go the other way as ordinary requests: `POST /messages` in the example.
- Swerve has no message history to resume from after a `Last-Event-ID`
  (`$sse->lastEventId()`), so a reconnecting client catches up from your storage.
- **On a reload or shutdown** (and at the end of the lingering of a recycled worker, see
  [Production](production.md)), every handler gets a `Swerve\WorkerStoppingException` at its
  wait, at once, and the browser reconnects to another worker.
- Behind a proxy, buffering must be off (nginx: `proxy_buffering off`).

## Raw connections

A request with `Connection: upgrade` and an `Upgrade` header, HTTP/1.1, whose body has been read to its
end, may be answered with `101`:

```php
$request->sendResponseHeaders(101, ['connection' => 'upgrade', 'upgrade' => 'example']);
```

The head goes out at once, and from then on the `ClientRequest` is the raw connection: `read()`
returns what the client sends (first, any bytes it sent right behind its Upgrade request) and
`write()` sends bytes as given, with no HTTP framing, timeouts or size limits. A protocol can be
spoken over it with `read()` and `write()`; a client that stops reading is dropped with `close()`.

- `read()` waits for the client and returns `''` when the client closes its side: say goodbye in
  your protocol, and return. When the worker stops, it throws a `Swerve\WorkerStoppingException`;
  a goodbye then is written inside `phasync::shielded()`, since every later wait throws it too.
- When the handler returns, the connection closes, after reading the client's last bytes for up to
  2 s so that your goodbye arrives.
- A recycled worker keeps its upgraded connections up to `--linger` seconds instead of draining
  them; see [Production](production.md#sizing).
- Without phasync-ext a worker holds 512 connections; see [Production](production.md#sizing).

## WebSockets

`Swerve\WebSocket` is the WebSocket protocol on this raw connection: handshake, framing, pings, close codes,
backpressure. Its contract is in [WebSockets and Server-Sent Events](websocket.md).

```php
Swerve\WebSocket::from($request, function (Swerve\WebSocket $ws) {
    foreach ($ws as $message) {
        $ws->send($message);
    }
});
```

## Choosing

| | Server-Sent Events | WebSocket |
|---|---|---|
| Direction | server to browser; the browser sends with ordinary requests | both ways, one connection |
| Browser API | `EventSource`, reconnects by itself | `WebSocket`, reconnects when you write it |
| Through proxies | ordinary HTTP; proxy buffering must be off | needs the proxy's upgrade support |

Both push a message to thousands of clients as fast as the workers can write, and both use
[publish and subscribe](publish-subscribe.md) to reach clients on every worker.

Next: [Publish and subscribe](publish-subscribe.md).
