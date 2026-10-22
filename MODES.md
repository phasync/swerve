# Swerve's three modes (design, Swerve 0.2)

Every application Swerve serves runs in exactly one mode. The mode is stated in `swerve.php`
(or implied, for Classic without a `swerve.php`), named in the startup log, and documented by
every adapter. Nothing about it is hidden.

| Mode | What happens per request | State between requests | Requests per process | For |
|---|---|---|---|---|
| **Classic** | the script runs from scratch with PHP's own request lifecycle, as under PHP-FPM | fresh every request | one, blocking | any PHP application, unmodified: WordPress, legacy code |
| **Serialized** | the application booted once; its handler runs per request | kept (statics, services) | one at a time per worker | frameworks that keep request state in services or statics: Laravel Octane-style, Yii, CakePHP, CodeIgniter, Laminas MVC, Spiral |
| **Concurrent** | the application booted once; requests are coroutines that overlap while they wait | kept, so request state must be scope-safe | many | scope-safe applications: PSR-15 stacks (Slim, Mezzio), Laravel and Symfony with their pools, real-time handlers. Node.js-like power and responsibility |

Classic and Serialized applications can declare **concurrent routes**: path prefixes that always
run in Swerve's workers in Concurrent mode. That is where WebSockets, server-sent events and other
long-lived or I/O-heavy endpoints live; their code must be written for cooperative concurrency.

## Configuration: `swerve.php` states the mode

`swerve.php` is the only entry point. It returns one of three values:

```php
// Classic. Without a swerve.php, `swerve <dir>` in a directory with index.php starts Classic
// (like `php -S`). With one, for options and concurrent routes:
return Swerve::classic(
    root: __DIR__ . '/public',
    router: 'index.php',
    concurrent: ['/ws/' => __DIR__ . '/realtime.php'],
);

// Serialized: boot once (the value is the booted application's handler), one request at a time.
return Swerve::serialized(
    require __DIR__ . '/bootstrap/swerve.php',
    concurrent: ['/events/' => __DIR__ . '/realtime.php'],
);

// Concurrent: any request handler that is scope-safe.
return Swerve::concurrent($app);   // a Swerve RequestHandler, a PSR-15 handler, or a closure
```

- A concurrent route maps a path prefix to a file that returns a concurrent handler. That file is
  loaded in the worker separately from the classic or serialized application, so the boundary
  between the two kinds of code is a file, not a convention.
- Adapters become plain functions that return one of these: `return Swerve\Laravel\serialized(__DIR__);`,
  `return Swerve\Symfony\concurrent(__DIR__);` and so on, each documenting its mode. Composer's
  `extra.swerve` adapter discovery and `--adapter` go away.
- The startup log line names the mode and how it runs, e.g. `Classic (extension request server, up
  to 32 processes)`, `Classic (php -S pool)`, `Serialized, 4 workers`, `Concurrent, 4 workers`.

## How Classic runs

Swerve's workers accept HTTP (and TLS), serve static files themselves, run concurrent routes, and
forward every other request to a **request runner**:

1. **ext-phasync's request server** when the extension is loaded (`phasync.serve`): a master that
   listens on a private Unix socket and forks one child per connection from Swerve; each child
   serves request after request on its connection with PHP's real request lifecycle (fresh state,
   native `header()`, `echo`, sessions, `exit()`), blocking. Swerve decides how many processes run:
   it opens a connection when every open one is busy, up to a limit, and closes connections that
   stay idle for 10 seconds, which ends their processes. The children are forked from a master that
   never runs a request, so they share its opcache.
2. **A pool of `php -S` processes** when the extension isn't available: the same growth and idle
   rules, HTTP on loopback, about 15% more CPU per request than PHP-FPM on WordPress.

### Protocol between Swerve and the request server

Frames: `[type: 1 byte][length: 4 bytes, big-endian][payload]`.

| Type | Direction | Payload |
|---|---|---|
| `HEAD` | Swerve → server | `serialize()` of `method`, `target`, `protocol`, `headers` (list of `[name, value]` pairs in order, duplicates kept), `remote` `[addr, port]`, `local` `[addr, port]`, `https`, `server_name`, `body` (bool) |
| `HEAD` | server → Swerve | `serialize()` of `status` and `headers` (list of `[name, value]`) |
| `BODY` | both | body bytes; length 0 ends the body. A request with `'body' => false` sends no BODY frames at all |
| `STOP` | server → Swerve | send no more request body (the script ended without reading it); Swerve sends the end-of-body frame at once, the server discards frames in flight until it |
| `ABORT` | Swerve → server | the client is gone (later: `connection_aborted()` as under FPM) |

Rules: the server reads up to the request's end-of-body frame before the next request; a fatal
error still ends with a response `HEAD` (500 if nothing was sent) and the end frame; the response
never closes the connection (a `Connection: close` from the script or the client is Swerve's
business on the client side); response output is batched in 64 KiB frames, sent when full, on
`flush()` and at the end of the request.

Measured on WordPress (Ryzen 9950X3D, 2 server cores, c=32, JIT 1054 everywhere): the request
server runs at PHP-FPM's cost with ext-phasync loaded (16.5 ms CPU per request; FPM alone 16.2,
FPM with the extension 16.5). End to end, Swerve in front of it serves ~118 req/s against nginx +
FPM's ~123. Known follow-ups: serve mode skips the extension's coroutine hooks (≈1.5%), Swerve's
front batches its client writes (≈1.5%).

## Serialized and Concurrent: every request inside `sapi()`

Each request in a worker runs inside ext-phasync's `sapi()`, so every request has its own output
buffers and `ob_*`, `header()`/`setcookie()`/`http_response_code()`, `php://input`, uploads and
session; and, through `Scope`, its own request superglobals, default time zone and locale. Without
the extension, application code is loaded through the plug (phasync-babel translation to
phasync-hooked replacements), which provides the same, slightly inferior (database drivers in C
stay blocking; preemption needs the extension).

A handler may produce its response either way:
- **returned**: a Swerve response, a PSR-7 response; anything echoed beside it is an error, as today;
- **echoed**: the handler returns nothing, and what it echoed, with the headers and status it set,
  is the response, exactly as a classic script.

So classic-style code moves into Serialized mode without being rewritten into PSR-7.

## WebSockets and server-sent events per mode

- **Classic**: `WebSocket::setup(static function (WebSocket $ws) use (...) { ... })` (and
  `EventStream::setup()` for SSE). The function must be static (no `$this`); it is serialized with
  Serializor and runs in a Swerve worker, which keeps the connection; only its `use` values travel,
  as copies, and values that can't travel (PDO, resources) throw at `setup()`. Afterwards the
  request behaves as if the client had disconnected: `connection_aborted()` is true, output is
  discarded, PHP's own abort rules apply (including `ignore_user_abort()`). `setup()` must come
  before any output.
- **Serialized and Concurrent**: return `new WebSocketResponse(function (WebSocket $ws) { ... })`
  (and the SSE equivalent). The function runs as a coroutine in the same process and outlives the
  request. In Serialized mode it keeps running while the worker takes the next requests (after the
  framework's cleanup), so it must not touch the framework's global state (facades, the container,
  the request's database connection): only its captured values and Swerve's and phasync's APIs.
- Classic and Serialized code reaches Swerve's other APIs (`Swerve::cache()`, `publish()`,
  `subscribe()`, `claim()`): in Classic through frames on the request server connection, executed
  by the forwarding worker; `subscribe()` there holds its process for as long as it waits.

## Not decided yet

- Hibernating Classic requests (an idea only: a parked request would free its process).
- How `Swerve::cache()`/`publish()` frames are numbered in the protocol (after the first Classic release).
- Windows/macOS for the request server (Linux first).
