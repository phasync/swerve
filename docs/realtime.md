# Realtime: Server-Sent Events, WebSockets and raw connections

A request in swerve may stay open as long as it likes: the worker serves other requests
meanwhile, since a waiting coroutine costs little (tens of kilobytes). `Swerve::upgrade()` takes
the connection off the request for a static closure, which keeps it for as long as it runs. The
type of its parameter says what it gets: a `Swerve\ServerSentEvents` stream, a `Swerve\WebSocket`,
or the raw `Swerve\ClientRequest`.

```php
while (Swerve::accept('public/', function () {
    if ('/events' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade(static function (Swerve\ServerSentEvents $sse): void {
            // ...
        });

        return;
    }
    // ...
})) {
}
```

From the call on, the request has no connection: `connection_aborted()` is true and output
throws. The request goes on to its end, and the closure runs once it has ended, in a `Scope` of
its own seeded with the request's state as it was at the call: `$_GET`, `$_POST`, `$_COOKIE`,
`$_FILES`, `$_REQUEST`, `$_SERVER`, `$_SESSION` (the data: the session itself was written and closed
with the request), the timezone and the intl locale.

The [chat room in `examples/sse-chat`](../examples/sse-chat) is a complete example over Server-Sent
Events, and [`examples/websocket-chat`](../examples/websocket-chat) over WebSockets; swerve's tests run
both.

## Server-Sent Events

The browser's `EventSource` reads a `text/event-stream` response and reconnects by itself when it
ends. From [`examples/sse-chat/swerve.php`](../examples/sse-chat/swerve.php):

```php
Swerve::upgrade(static function (ServerSentEvents $sse): void {
    // A null every 15 s without a message, for a keep-alive. When the worker stops (a
    // shutdown or reload), the loop throws a WorkerStoppingException, swerve cuts the
    // stream, and the browser reconnects to another
    try {
        foreach (Swerve::subscribe('chat', heartbeat: 15) as $message) {
            // The keep-alive makes sure a client that left is noticed: this write fails
            null === $message ? $sse->comment('keep-alive') : $sse->send($message);
        }
    } catch (IOException) {
        // The client left
    } catch (SubscriberLagException) {
        // Too far behind: the browser's EventSource reconnects
    }
});
```

The head goes out before the closure runs; its fields, `Last-Event-ID` and `HEAD` are in
[WebSockets and Server-Sent Events](websocket.md#server-sent-events).

- When the client leaves, the next `send()` throws `phasync\IOException`: that is how a producer
  learns it can stop. One that stopped reading without closing its connection (a network gone)
  fills its socket buffer, and a write then waits 60 s and throws `phasync\TimeoutException`; a
  keep-alive comment every 15 s makes sure there are writes to fail.
- Messages go the other way as ordinary requests: `POST /messages` in the example.
- Swerve has no message history to resume from after a `Last-Event-ID` (`$sse->lastEventId()`),
  so a reconnecting client catches up from your storage.
- **On a reload or shutdown**, the closure gets a `Swerve\WorkerStoppingException` at its wait, and
  the browser reconnects to another worker.
- Behind a proxy, buffering must be off (nginx: `proxy_buffering off`).

## WebSockets

`Swerve\WebSocket` is the WebSocket protocol on the connection: handshake, framing, pings, close
codes, permessage-deflate, backpressure. Its contract is in
[WebSockets and Server-Sent Events](websocket.md).

```php
Swerve::upgrade(static function (Swerve\WebSocket $ws): void {
    foreach ($ws as $message) {
        $ws->send($message);
    }
});
```

`upgrade()` checks the handshake first, and throws `Swerve\UpgradeException` (answered with its
status) for a request that is not a valid one.

### Inline, or moved to WebSocket workers

The handler runs in the request, which waits for it: it has the request's objects, superglobals
and session data for as long as the WebSocket is open, and `upgrade()` returns once it closed. In a
`swerve.php` that loops, other requests run meanwhile. One that accepts one request per run, as
WordPress's, has its worker busy for as long as the WebSocket is open: a few WebSockets are fine,
a chat for every visitor is not.

Given a bootstrap file, the WebSocket moves instead. Its `101` is sent by the worker, and the
connection goes on to a WebSocket worker that loaded the file once, as it started, in the global
scope:

```php
// in the request
Swerve::upgrade('chat_socket', __DIR__ . '/chat-bootstrap.php');
```

```php
// chat-bootstrap.php: what chat_socket() needs, and nothing per user (the Composer autoload is
// loaded already)
function chat_socket(Swerve\WebSocket $ws): void
{
    $room = $_GET['room'] ?? 'lobby';                 // the request's state moves along
    foreach ($ws as $message) {
        $ws->send("$room: $message");
    }
}
```

- Each bootstrap file has its own WebSocket workers, up to one per worker, started as they are
  first needed. Every WebSocket a worker moves with a file goes to the same one; there is no
  rebalancing.
- The handler is the name of a function or `[class, static method]` that the file or the Composer
  autoload defines. A static closure moves too when phasync/serializor is installed, serialized
  with what it captures.
- It runs in a `Scope` of its own, seeded with the request's `$_GET`, `$_POST`, `$_COOKIE`,
  `$_FILES`, `$_REQUEST`, `$_SERVER`, `$_SESSION` data, timezone and intl locale as they were at
  the call.
- WebSocket workers take part in `Swerve::publish()`, `subscribe()`, `cache()` and `container()`
  as the workers do: the bootstrap file adds the services its handlers need, and a limit counts
  across every process.
- One WebSocket worker serves many WebSockets at once, which share its globals. In WordPress,
  `$wpdb`, the current user and the object cache are such globals: keep what is per user in the
  handler, and give WebSockets a database connection each (a limited container service), or
  talk to the request side with `Swerve::publish()`.
- A bootstrap file that throws is logged as critical: its WebSockets are closed, and it is tried
  again a second later. A handler it doesn't define closes its WebSocket, logged as critical.

## Raw connections

A closure taking a `ClientRequest` answers the request itself. A request with `Connection: upgrade`
and an `Upgrade` header may be answered with `101`:

```php
Swerve::upgrade(static function (Swerve\ClientRequest $client): void {
    $client->sendResponseHeaders(101, ['connection' => 'upgrade', 'upgrade' => 'example']);
    while ('' !== $bytes = $client->read()) {
        $client->write($bytes);
    }
});
```

The head goes out at once, and from then on the `ClientRequest` is the raw connection: `read()`
returns what the client sends (first, any bytes it sent right behind its Upgrade request) and
`write()` sends bytes as given, with no HTTP framing, timeouts or size limits. A client that stops
reading is dropped with `close()`.

- `read()` returns `''` when the client closes its side: say goodbye in your protocol, and return.
  When the worker stops, it throws a `Swerve\WorkerStoppingException`; a goodbye then is written
  inside `phasync::shielded()`, since every later wait throws it too.
- When the closure returns, the connection closes, after reading the client's last bytes for up to
  2 s so that your goodbye arrives.
- A raw connection needs a `swerve.php` that keeps calling `accept()`: only WebSockets move to a
  WebSocket worker.

A PSR-7 handler gets the same by returning a `101` response: see
[Requests and responses](requests-and-responses.md#psr-7-handlers).

## Choosing

| | Server-Sent Events | WebSocket |
|---|---|---|
| Direction | server to browser; the browser sends with ordinary requests | both ways, one connection |
| Browser API | `EventSource`, reconnects by itself | `WebSocket`, reconnects when you write it |
| Through proxies | ordinary HTTP; proxy buffering must be off | needs the proxy's upgrade support |

Both push a message to thousands of clients as fast as the workers can write, and both use
[publish and subscribe](publish-subscribe.md) to reach clients on every worker.

Next: [Publish and subscribe](publish-subscribe.md).
