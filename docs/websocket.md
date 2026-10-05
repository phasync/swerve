# WebSockets and Server-Sent Events

`Swerve\WebSocket` speaks RFC 6455 over the `101` connection of a [`ClientRequest`](requests-and-responses.md),
and `Swerve\ServerSentEvents` writes an event stream on one. Both are in swerve itself, so the adapters
(swerve-psr15, Symfony, Tether) share one implementation. This page is their contract; the tests in
`tests/WebSocketTest.php` and `tests/ServerSentEventsTest.php` pin every line of it, over raw sockets, with and
without phasync-ext, and nothing differs.

## WebSocket

```php
return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    Swerve\WebSocket::from($request, function (Swerve\WebSocket $ws) {
        foreach ($ws as $message) {          // ends when the connection closes
            $ws->send("echo: $message");
        }
    });
});
```

| | |
|---|---|
| `WebSocket::from($request, $callback, subprotocols: [], origins: null, maxMessage: 1 MiB)` | handshake, then the callback in a coroutine of its own; returns when the connection has closed |
| `WebSocket::accept($request, ...)` | the same handshake; returns the `WebSocket` (or `null` after refusing), and the handler reads it itself |
| `receive(): ?string`, `foreach ($ws as $m)` | the next message, `null` once closed; `isBinary()` tells what the last was |
| `send($text)`, `sendBinary($data)` | one message; from any coroutine; ignored once closed |
| `end($code = 1000, $reason = '')` | close with a code; once; from any coroutine |
| `$onMessage`, `$onClose` | `phasync\Util\Event`s: `(string $data, bool $binary)` and `(int $code, string $reason)` |
| `$subprotocol`, `isClosed()` | the subprotocol chosen, or `null` |

`from()` closes the connection when the callback returns (1000) or throws (1011, and swerve logs the
exception). The connection is read all the time, also when the callback only sends: a client that leaves cancels
the callback, so a loop forwarding a subscription ends with its client.

Messages come in two styles that do not mix: `receive()` and `foreach` pull them, `$onMessage` has them pushed
one at a time in arrival order. `receive()` throws `LogicException` while `$onMessage` has listeners.

```php
Swerve\WebSocket::from($request, function (Swerve\WebSocket $ws) {
    $ws->onMessage->listen(fn (string $data) => Swerve::publish('chat', $data));   // events in
    foreach (Swerve::subscribe('chat') as $out) {                                  // sequential code out
        $ws->send($out);
    }
});
```

`accept()` leaves the reading to the handler: `receive()` reads the socket in the caller's coroutine, `$onMessage`
is not called, and the handler must call `end()` (a `finally` does) before it returns, because that writes the
close frame.

```php
if (null === $ws = Swerve\WebSocket::accept($request)) {
    return;                                  // refused: the response is sent
}
try {
    foreach ($ws as $message) {
        $ws->send($message);
    }
} finally {
    $ws->end();
}
```

### Handshake

The request is a WebSocket request when `Upgrade` has the token `websocket` and `Connection` the token `upgrade`
(comma lists and repeated lines, any case). Otherwise `accept()` answers and returns `null`:

| Status | When | Headers |
|---|---|---|
| 426 | not a WebSocket request: `Upgrade` or `Connection` lacks its token | `Upgrade: websocket` |
| 400 | a WebSocket request that is not valid: not `GET`, not HTTP/1.1, a body, `Sec-WebSocket-Version` other than `13`, a key that is not 16 bytes of base64 or is given twice | `Sec-WebSocket-Version: 13` |
| 403 | `origins` is given, and the `Origin` is not in it | |

Otherwise the answer is `101` with `Sec-WebSocket-Accept`. A refusal is an ordinary response: the connection
serves the next request.

- `origins` compares case-insensitively and is off by default. A client that sends no `Origin` (not a browser) is
  let through: the list protects browsers' cross-site connections, not the server from other programs.
- `subprotocols` is your list in your order of preference; the first one the client offered is chosen and sent back
  as `Sec-WebSocket-Protocol`, exposed as `$ws->subprotocol`. None in common: none is sent, and the connection opens.
- Extensions are never accepted (no permessage-deflate): `Sec-WebSocket-Extensions` is ignored, so frames never
  carry reserved bits.

### States

```
handshake --101--> open --end() or the client's close frame--> closing --client closes, or 2 s--> closed
```

- **open**: frames flow both ways. `isClosed()` is false.
- **closing**: `end()` ran: the close frame is written (waiting up to 5 s for a client that does not read), the
  sending side is shut (FIN), `receive()` returns `null`, `send()` is ignored and `$onClose` fires, once. The
  client's reply and anything else it sends are read and dropped.
- **closed**: swerve closes the TCP connection when the client has closed its side, or 2 s after the handler
  returned. Bytes the client sent after its close frame are never looked at.

Whoever says goodbye first sets the code `$onClose` reports: the client's close frame (1005 when it carries no
code), the code given to `end()`, or a protocol code from the table below.

### Close codes

| Code | Sent by the server when | `$onClose` gets |
|---|---|---|
| 1000 | the callback returned; `end()`; the client sent an empty close frame, or 1000 | what the client sent, or 1005 for none |
| 1001 | the client's connection ended without a close frame, or swerve drains (shutdown, reload) | 1006 |
| 1002 | protocol error: a frame not masked; a reserved bit or opcode; a control frame fragmented or over 125 bytes; a continuation of nothing, or a new message inside one; a length with the top bit set; a close frame of one byte; a close code that may not be sent | 1002 |
| 1007 | text, or a close reason, that is not UTF-8 (checked on the whole message, so a character may be split across fragments) | 1007 |
| 1009 | a message over `maxMessage`, also as the sum of its fragments (the length is checked before the payload is read) | 1009 |
| 1011 | the callback or an `$onMessage` listener threw | 1011 |

A close code from the client is echoed when it is 1000-1003, 1007-1014 or 3000-4999; any other (0-999, 1004,
1005, 1006, 1015, 2000-2999, 5000 and up) is a protocol error. The reason is not echoed. `end()` takes the same
codes, a reason of at most 123 bytes that is UTF-8, and throws `ValueError` otherwise: 1005 and 1006 never go on the
wire. `send()` throws `ValueError` for text that is not UTF-8; use `sendBinary()`.

### Guarantees

- **Order.** Messages reach `$onMessage` and `receive()` in the order they were sent. Messages sent from one
  coroutine arrive in its order; frames of different coroutines never interleave, whatever their size.
- **Control frames.** A ping is answered at once with a pong carrying its payload, also between the fragments of a
  message. A pong nobody asked for is ignored. A ping or pong is never delivered to the application.
- **Backpressure out.** `send()` waits for a client that reads slowly, and the worker buffers nothing for it. A
  client that reads nothing for `WRITE_TIMEOUT` (30 s) is given up: the connection is closed without a close frame.
- **Backpressure in.** A client that sends faster than the application takes is not read from, so TCP holds it
  back. With `foreach` or `receive()` under `from()`, up to `INBOX` (64) messages wait, then the reading
  stops; with `$onMessage`, the reading waits for the listener; under `accept()`, nothing is read between
  `receive()` calls. Memory stays at about `maxMessage` plus `INBOX` messages.
- **Cancellation.** When the connection ends under `from()` (the client left or reset, or a drain), a callback
  still running is cancelled, and its `CancelledException` is not an error. A reset ends the callback without a
  line in the log; `$onClose` fires once with 1006.
- **Drain.** A shutdown or reload closes every open WebSocket with 1001, so the process exits within its grace
  period, with status 0 and a clean log. Clients reconnect, as they must after any 1001.
- **Many sockets.** A socket costs a coroutine and no thread; a worker serves ordinary requests promptly with 200
  open (the test), and holds 512 connections without phasync-ext, see [Production](production.md#sizing).

### Pings

The server pings every open connection every `WebSocket::$pingInterval` seconds (15), with an empty ping, from one
coroutine for all connections in the worker, started with the first connection and ended with the last. A ping is
skipped for a client whose socket buffer is full: it is not reading anyway, and its own `send()` times out. Pongs
are not required.

### Limits and settings

| | Default | Where |
|---|---|---|
| Largest message | 1 MiB | `maxMessage:` of `from()` and `accept()`, per socket |
| Messages waiting in the pull style | 64 | `WebSocket::INBOX` |
| Control frame | 125 bytes | the protocol |
| Close reason | 123 bytes | the protocol |
| `send()` wait for a client that does not read | 30 s | `WebSocket::WRITE_TIMEOUT` |
| Close frame wait | 5 s | `WebSocket::CLOSE_TIMEOUT` |
| Ping interval | 15 s | `WebSocket::$pingInterval`, set it once at start-up |

TLS is the proxy's: the connection swerve sees is `ws://` ([Production](production.md)).

## Server-Sent Events

```php
return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    $sse = new Swerve\ServerSentEvents($request);
    foreach (Swerve::subscribe('news', heartbeat: 15) as $message) {
        null === $message ? $sse->comment('keep-alive') : $sse->send($message, event: 'news');
    }
});
```

- The constructor sends `200`, `content-type: text/event-stream`, `cache-control: no-cache` and
  `x-accel-buffering: no`, and puts the head on the wire at once, before any event exists. More headers go in its
  second argument.
- The body is chunked on HTTP/1.1 (a `content-length` is never sent) and ends with the connection on HTTP/1.0. Each
  event is one write, so one chunk. The stream ends when the handler returns.
- `send($data, $event = null, $id = null, $retry = null)` writes the fields `event`, `id`, `retry` and then one
  `data:` line for every line of `$data` (split on `\r\n`, `\r` or `\n`), so the browser joins them again; empty data
  is one empty `data:` line. CR and LF are removed from `$event` and `$id` (and NUL from `$id`): they cannot inject
  a field.
- `comment($text)` writes a comment line: ignored by the browser, it keeps the stream open through proxies and
  makes the next write fail when the client is gone.
- `lastEventId()` is the `Last-Event-ID` the client sent when it reconnected, or `null`. Swerve keeps no history:
  resume from your own storage.
- **Disconnect.** A write to a client that left throws `phasync\IOException`. Let it leave the handler: the
  exchange ends without a line in the log, so a loop ends with its client. A client that stopped reading is given
  up by the write timeout, see [Realtime](realtime.md).
- A `HEAD` request gets the head and an empty body; `send()` and `comment()` then throw `IOException`, since
  there is no stream to send to.

Next: [Realtime](realtime.md) and [Publish and subscribe](publish-subscribe.md).
