# Swerve — A Next-Generation PHP Application Server

Swerve is a high-performance PHP application server that runs traditional PHP applications,
persistent application workers and highly concurrent asynchronous applications within one
architecture.

Built on phasync, Swerve lets developers write conventional, sequential-looking PHP while serving
thousands of concurrent HTTP requests, WebSocket connections and long-lived coroutines.

Unlike servers that require applications to adopt a different programming model, Swerve keeps
familiar PHP semantics and extends what PHP applications can do.

## Three execution modes

- **Classic** — each request starts with a fresh PHP execution context, as under PHP-FPM.
  Existing applications such as WordPress run unmodified.
- **Serialized** — the application boots once and handles one request at a time per worker, as
  with RoadRunner, Laravel Octane or FrankenPHP's worker mode.
- **Concurrent** — the application boots once and handles many requests concurrently on fibers
  and coroutines, as in Node.js or asynchronous C#.

Classic and Serialized are mutually exclusive, but either can be combined with concurrent
routes: path prefixes that always run in Concurrent mode. Existing applications can expose
high-performance asynchronous endpoints without changing their primary execution model.

## Choosing the mode

An application states its mode in `swerve.php`, its single entry point, by returning it:

```php
// Classic. Without a swerve.php, `swerve public/` in a directory with an index.php starts
// Classic, like `php -S`. With one, for options and concurrent routes:
return Swerve::classic(
    root: __DIR__ . '/public',
    router: 'index.php',
    concurrent: ['/ws/' => __DIR__ . '/realtime.php'],
);

// Serialized: boot once, then one request at a time per worker.
return Swerve::serialized(
    require __DIR__ . '/bootstrap/swerve.php',
    concurrent: ['/events/' => __DIR__ . '/realtime.php'],
);

// Concurrent: any request handler that is safe for overlapping requests.
return Swerve::concurrent($app);
```

Each concurrent route maps a path prefix to a file that returns a concurrent handler, loaded in
the workers apart from the main application. Framework adapters are plain functions that return
one of these (`return Swerve\Laravel\serialized(__DIR__);`), and the startup log names the mode
the application runs in.

## Process architecture

Swerve runs a master process and several front workers, all written in PHP.

The front workers accept HTTP connections, serve static files, execute concurrent request
handlers and manage persistent connections such as WebSockets.

In Classic mode, requests are dispatched to separate PHP processes: the phasync extension's
request server, reached over a compact framed protocol, which gives every request PHP's own
fresh lifecycle. The processes are forked on demand and share one opcache; their number follows
the connections Swerve keeps to them. These processes reach Swerve's services over the same
protocol.

Front workers are interconnected and send messages to each other directly. The master
coordinates shared state: it keeps the shared cache layer and invalidates the local cache layer
in each worker, and it holds the routing information for interprocess messaging.

Swerve's integrated services:

- `Swerve::cache()` — a shared PSR-16 cache.
- `Swerve::publish()` and `Swerve::subscribe()` — interprocess publish/subscribe messaging.
- `Swerve::claim()` — coordination through locking.
- WebSockets and server-sent events — persistent connections from any mode (below).

## One PHP environment in every mode

Code sees the same PHP environment whatever mode it runs in: `$_SERVER` as PHP-FPM behind nginx
sets it, `$_GET`/`$_POST`/`$_COOKIE`/`$_FILES`, `php://input`, `header()`/`setcookie()`/
`http_response_code()`, `echo` and output buffering, sessions, connection status, the default
time zone and locale — all per request.

Classic gets this from PHP's own request lifecycle. In Serialized and Concurrent mode, every
request runs inside the phasync extension's `sapi()`, which gives it its own output, headers,
request body and session, while `Scope` identifies the logical request independently of the
fibers executing it and keeps its superglobals, time zone and locale. Applications still manage
their own shared mutable state, but developers keep the familiar PHP request-handling APIs.

## WebSockets from traditional PHP

A traditional PHP application can open persistent WebSocket connections. WordPress, for
example, can call:

```php
WebSocket::setup(static function (WebSocket $ws) {
    foreach (Swerve::subscribe('notifications') as $message) {
        $ws->send($message);
    }
});
```

Swerve serializes the closure with Serializor and hands it, with the client's connection, to a
front worker, where it runs as a coroutine. The original request then behaves as if the client
had disconnected and finishes normally, while the WebSocket stays open. Other WordPress requests
publish messages through `Swerve::publish()`.

In Serialized and Concurrent mode the handler instead returns
`new WebSocketResponse(function (WebSocket $ws) { ... })`; the function runs as a coroutine in
the same process and outlives the request. Server-sent events work the same way.

## phasync and the native extension

phasync schedules coroutines on PHP fibers. Its native extension, which most installations will
run, adds:

- **Preemptive scheduling** at backward-jumping PHP opcodes, so CPU-bound loops cannot
  monopolize a worker.
- **Transparent asynchronous I/O** through stream interception: every PHP stream operation, and
  dozens of blocking functions (sockets, pipes, processes, sleeps, curl, DNS), wait cooperatively,
  so existing libraries participate in concurrency unchanged.
- **A native thread pool** for file system calls on slow or network file systems.
- **Integration with the event loop** through suspension slots and file-descriptor-based
  completion notifications.
- **`sapi()`**, per-request output, headers, request body and sessions inside one process, and
  the request server behind Classic mode.

Together these let much existing PHP code take part in concurrent execution without async/await
syntax or callback-oriented programming.

## Performance

On WordPress, Classic mode on the extension's request server runs at PHP-FPM's cost per request.

## Design philosophy

Swerve's central idea is that PHP's application lifecycle, its concurrency model and its
programming interface should not be inseparably coupled.

Traditional applications keep request isolation. Persistent applications avoid repeated
initialization. Concurrent applications run thousands of overlapping operations with ordinary
PHP control flow. All three modes share Swerve's messaging, caching, locking and persistent
connection infrastructure.

Rather than introducing a new programming paradigm, Swerve extends PHP's existing model to
support modern, persistent, event-driven applications — from WordPress to real-time interfaces
and multiplayer simulations.

## Notes: running without the extension

Swerve works on standard PHP without the extension (Windows users will run it this way):

- Classic mode runs on a pool of `php -S` processes over HTTP instead of the request server,
  at somewhat more CPU per request.
- Serialized and Concurrent mode load application code through phasync/babel, a source
  translator that redirects blocking functions and classes to coroutine-compatible PHP
  implementations; the per-request environment is provided in PHP. Database drivers written in C
  stay blocking, and there is no preemption.
- Before PHP 8.6, `stream_select()` limits a worker to about 1,000 open connections; from
  PHP 8.6 that limit is gone.
