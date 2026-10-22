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
- **Persistent** — the application boots once and handles one request at a time per worker, as
  with RoadRunner, Laravel Octane or FrankenPHP's worker mode.
- **Async** — the application boots once and handles many requests concurrently on fibers
  and coroutines, as in Node.js or asynchronous C#.

An application picks one of the three as its base mode. In Classic or Persistent mode, path
prefixes can still run in Async mode: async routes. Existing applications can expose
high-performance asynchronous endpoints without changing their primary execution model.

## Choosing the mode

An application states its mode in `swerve.php` by returning it:

```php
use Swerve\{ClassicMode, PersistentMode, AsyncMode};

// Classic: static files from public/, everything else that doesn't exist runs public/index.php,
// as nginx's `try_files $uri $uri/ /index.php`.
return ClassicMode::serve('public')
    ->withAsync('/ws/', function (ClientRequest $request): void { /* ... */ });

// Persistent: boot once, then one request at a time per worker.
return PersistentMode::serve('public', require __DIR__ . '/bootstrap/swerve.php');

// Async: any handler that is safe for overlapping requests.
return AsyncMode::serve('public', $app);
```

Without a `swerve.php`, the command line is shorthand for Classic, as with `php -S`: `swerve`
serves the current directory through its `index.php`, `swerve public` serves `public/`, and
`swerve public router.php` sends every path that doesn't exist to `router.php`. Swerve serves
static files itself in every mode; dotfiles, PHP source and anything outside the public root
never.

The handler can be a PSR-15 request handler, a PSR-7 closure, Swerve's native
`function (ClientRequest $request): void`, or a plain `function (): void` that reads superglobals
and echoes like a classic script. Framework adapters add their own types, such as a Symfony
kernel, and may refuse a mode they can't run safely. `swerve.php` runs in each worker, and the
startup log names the mode the application runs in.

Async routes share the process with the persistent application. Like requests in Node.js
or ASP.NET, they live alongside other requests in the same process, here alongside the request
the worker is currently serving. PHP's per-request environment is their own: superglobals,
headers, output, session, time zone and locale follow the route's own `Scope`. Process-wide
state, such as static properties and singletons, is shared, so per-request data belongs in the
request or its `Scope`, not in globals.

phasync/doctor scans an application for state that is not ready for Persistent or Async
mode (statics, globals, shared connections, process-wide locale and session use) and suggests
the fix for each finding.

## Process architecture

Swerve runs a master process and several front workers, all written in PHP.

The front workers accept HTTP connections, serve static files, run async routes
and manage persistent connections such as WebSockets.

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

Classic gets this from PHP's own request lifecycle. In Persistent and Async mode, every
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

The closure runs in the front worker, not in the WordPress request: WordPress is not loaded
there. It can use plain PHP, the values it captured (which must be serializable) and Swerve's
services (`publish()`, `subscribe()`, `cache()`, `claim()`); work that needs WordPress belongs in
the request before `setup()`, or in requests that publish to the socket's channel.

In Persistent and Async mode the handler instead returns
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

Classic mode runs on a request server written for it in the phasync extension: forked
processes sharing one opcache, each request with PHP's own fresh lifecycle, the same model as
PHP-FPM. On WordPress it runs at PHP-FPM's cost per request.

<!-- Numbers to add: WordPress home page, same machine and worker count, php-fpm + nginx vs
Swerve Classic, req/s and p99; then Persistent and Async from the Yii3 benchmark. -->

Persistent and Async mode remove the per-request bootstrap, and Async mode also lets
requests overlap while they wait on databases, HTTP APIs or other I/O.

## Design philosophy

Swerve's central idea is that PHP's application lifecycle, its concurrency model and its
programming interface should not be inseparably coupled.

Traditional applications keep request isolation. Persistent applications avoid repeated
initialization. Async applications run thousands of overlapping operations with ordinary
PHP control flow. All three modes share Swerve's messaging, caching, locking and persistent
connection infrastructure.

Rather than introducing a new programming paradigm, Swerve extends PHP's existing model to
support modern, persistent, event-driven applications — from WordPress to real-time interfaces
and multiplayer simulations.

## Notes: running without the extension

Swerve works on standard PHP without the extension (Windows users will run it this way):

- Classic mode runs on a pool of `php -S` processes over HTTP instead of the request server,
  at somewhat more CPU per request.
- Persistent and Async mode load application code through phasync/babel, a source
  translator that redirects blocking functions and classes to coroutine-compatible PHP
  implementations; the per-request environment is provided in PHP. Database drivers written in C
  stay blocking, and there is no preemption. Async mode then helps most with HTTP calls,
  sockets, WebSockets and server-sent events, and less with database-heavy requests.
- Before PHP 8.6, `stream_select()` limits a worker to about 1,000 open connections; from
  PHP 8.6 that limit is gone. With the extension, its epoll/kqueue poller has no such limit on
  any PHP version.
