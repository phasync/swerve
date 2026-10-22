# Swerve's three modes (design, Swerve 0.2)

Every application Swerve serves runs in exactly one mode. The mode is stated in `swerve.php`
(or implied by the command line, for Classic without a `swerve.php`), named in the startup log,
and documented by every adapter. Nothing about it is hidden.

| Mode | What happens per request | State between requests | Requests per process | For |
|---|---|---|---|---|
| **Classic** | the script runs from scratch with PHP's own request lifecycle, as under PHP-FPM | fresh every request | one, blocking | any PHP application, unmodified: WordPress, legacy code |
| **Persistent** | the application booted once; its handler runs per request | kept (statics, services) | one at a time per worker | frameworks that keep request state in services or statics: Laravel Octane-style, Yii, CakePHP, CodeIgniter, Laminas MVC, Spiral |
| **Async** | the application booted once; requests are coroutines that overlap while they wait | kept, so request state must be scope-safe | many | scope-safe applications: PSR-15 stacks (Slim, Mezzio), Laravel and Symfony with their pools, real-time handlers. Node.js-like power and responsibility |

Classic and Persistent applications can declare **async routes**: path prefixes that always run
in Swerve's workers in Async mode. That is where WebSockets, server-sent events and other
long-lived or I/O-heavy endpoints live; their code must be written for cooperative concurrency.

## Configuration: `swerve.php` returns the mode

```php
use Swerve\{ClassicMode, PersistentMode, AsyncMode};

// Classic: static files from public/, everything else that doesn't exist goes to public/index.php.
return ClassicMode::serve('public');
return ClassicMode::serve('public', 'app/front.php')          // a router outside the web root
    ->withAsync('/ws/', function (ClientRequest $request): void { /* ... */ });

// Persistent: boot once, one request at a time per worker.
return PersistentMode::serve('public', require __DIR__ . '/bootstrap/swerve.php')
    ->withAsync('/events/', fn (ServerRequestInterface $r): ResponseInterface => /* ... */);

// Async: any handler that is safe for overlapping requests.
return AsyncMode::serve('public', $app);
```

- `ClassicMode::serve(string $publicRoot = '', ?string $router = null)`;
  `PersistentMode::serve(?string $publicRoot, object $handler)`;
  `AsyncMode::serve(?string $publicRoot, object $handler)`. On PHP 8.4+ `new ClassicMode(...)`
  takes the same arguments.
- Every path is relative to `swerve.php`'s directory (absolute paths are accepted). `$router`
  defaults to `{$publicRoot}/index.php`. Startup fails if the public root or the router doesn't
  exist.
- `$publicRoot = null` (Persistent and Async only) serves no static files. There is no default:
  a project root is rarely safe to publish (`composer.json`, `vendor/`, `storage/`). Adapters
  pass their framework's directory.
- `withAsync(string $pattern, object $handler)` returns a new instance (immutable, as PSR-7's
  `with*`). The pattern is a regular expression body matched against the path. One that starts
  with `/` is anchored at the start of the path (Swerve prepends `^`), so `'/ws/'` is a prefix;
  any other pattern matches anywhere, as nginx's `location ~`: `'\.scss$'`. Write `^` to anchor a
  pattern that starts otherwise, e.g. `'^(?i)/admin/'`, and escape `.` in plain prefixes. Swerve
  compiles it with the delimiter `\x01` and the modifiers `Du` (a raw `\x01` byte in a pattern
  throws): `D`, `$` is the end of the path; `u`, patterns and paths are UTF-8, and an invalid
  pattern throws at startup. Other options are written inline (`(?i)`, `(?x)`). Patterns match
  the decoded, normalised path without the query string; paths with control characters or
  invalid UTF-8 are rejected with 400 before any routing. The first match in declaration
  order wins; adding the same pattern twice throws. Async routes come before static files, so a
  pattern can take over existing files (compile `.scss` on request) or paths that don't exist
  (build `style.css` from `style.scss`). With many patterns, Swerve can combine them into one
  alternation, as FastRoute does, without changing the API.
- `swerve.php` is evaluated in each worker, after the fork, never in the master: the master holds
  no application code or state (clean hard reloads, nothing shared across forks by accident). The
  master learns what it needs (mode, public root, router, async patterns) from the first worker.
  In Classic, code in `swerve.php` affects only the async handlers, never the request server's
  processes; in Persistent mode it runs in the worker beside the booted application.
- Async handlers run in Swerve's workers. In a Classic application they can't see the
  application's globals or functions, even though they are written in the same file.
- The startup log line names the mode and how it runs, e.g. `Classic (extension request server, up
  to 32 processes)`, `Classic (php -S pool)`, `Persistent, 4 workers`, `Async, 4 workers`.
- Composer's `extra.swerve` adapter discovery and `--adapter` go away.

### The command line is shorthand for `ClassicMode`

Paths on the command line are relative to the current directory, as with `php -S`.

| Command | Public root | Router |
|---|---|---|
| `swerve` | `.` | `./index.php` |
| `swerve public` or `swerve -t public` | `public/` | `public/index.php` |
| `swerve router.php` | `.` | `./router.php` |
| `swerve public router.php` | `public/` | `./router.php` |

- A positional argument that is a directory is the public root; a file is the router; anything
  else is an error.
- With a `./swerve.php`, plain `swerve` uses it; positional arguments beside it are an error
  ("swerve.php exists; configure there").
- `swerve` never looks in parent directories. When the current directory has neither
  `swerve.php` nor `index.php`, the error names the nearest `swerve.php` above it, if any.
  `composer exec swerve` runs as `swerve` in the directory of the nearest `composer.json`.
- Unlike `php -S`, the router runs only for paths that don't exist. `php -S` routers that
  `return false` for existing files keep working: Swerve has already served those.

## Request routing and static files

The same order in every mode, nginx's `try_files $uri $uri/ /index.php`:

1. the first matching async pattern: its handler;
2. an existing file under the public root that isn't `.php`: served by Swerve;
3. Classic: an existing `.php` file runs as itself; followed by `/`, it runs with the rest of the
   path as `PATH_INFO` (`/index.php/2024/hello`, and `/test.php/` with `PATH_INFO=/`), as
   `php -S`, Apache and the usual WordPress nginx setup do;
4. a directory requested with a trailing slash runs its `index.php` (Classic) or serves its
   `index.html`; requested without one, it is a 301 to the path with the slash (query string kept)
   when it has such an index file;
5. everything else: Classic's router, or the Persistent or Async handler. That includes a
   directory without an index file (never a listing), `/test` when only `test.php` exists (no
   extensionless mapping) and a static file followed by `/`.

Swerve's workers serve static files themselves: Content-Type, ETag/Last-Modified, `HEAD`, Range;
with the extension, file reads go to its thread pool. Never served in any mode: dotfiles, `.php`
source, anything resolving outside the public root (symlinks included). For a router outside the
public root, `SCRIPT_FILENAME` is its real path and `SCRIPT_NAME` is `/` plus its file name, as
nginx sets them.

## Handlers and adapters

`PersistentMode::serve()`, `AsyncMode::serve()` and `withAsync()` take any object, and an adapter
runs it:

```php
interface Adapter
{
    /** Called once at startup; throw to refuse a mode you can't run safely. */
    public function canHandle(object $handler, Mode $mode): bool;

    public function handle(ClientRequest $request, object $handler): void;
}

Swerve\Handlers::register(new SymfonyAdapter());   // from the adapter's autoload "files"
```

- At startup Swerve asks every registered adapter. Exactly one must answer yes. None: startup
  fails, naming the package for known types ("install phasync/swerve-symfony"). More than one:
  startup fails and suggests choosing with a first-class callable, e.g. `$kernel->handle(...)`.
- Per request, Swerve calls the chosen adapter's `handle()` directly. One-time work belongs in the
  adapter's constructor or its first `handle()`.
- The mode is the user's choice; an adapter may refuse a combination it knows is unsafe (a stock
  Symfony kernel in `AsyncMode`) at startup, with the reason.
- Several adapters coexist: `swerve-symfony` handles `HttpKernelInterface` and `Request` closures,
  `swerve-laravel` its kernel, and so on.

Core ships four adapters:

| Handler | Runs as |
|---|---|
| `function (ClientRequest $request): void` | Swerve's native handler: reads the body and writes the response on the request |
| `function (ServerRequestInterface $request): ResponseInterface` | PSR-7; also `$psr15->handle(...)` |
| a PSR-15 `RequestHandlerInterface` | PSR-15 |
| `function (): void` | a classic script: superglobals, `php://input`, `echo`, `header()`; the output and headers become the response |

Swerve supports two interfaces and favours no framework: PHP's own SAPI interface, on which every
framework already runs under PHP-FPM (a Symfony handler can be
`fn () => $kernel->handle(Request::createFromGlobals())->send()`), and PSR-7/PSR-15, the one typed
standard, which phasync implements. `ClientRequest` is the native shape, and the raw stream for
protocols that aren't request/response.

## How Classic runs

Swerve's workers accept HTTP (and TLS), serve static files and run async routes, and forward every
other request to a **request runner**:

1. **ext-phasync's request server** when the extension is loaded (`phasync.serve`): a master that
   listens on a private Unix socket and works as PHP-FPM with `pm = ondemand`: its children accept
   connections on that socket and run each request with PHP's real request lifecycle (fresh state,
   native `header()`, `echo`, sessions, `exit()`), blocking. The master forks a child when a
   connection waits and none is idle, up to `--classic-workers` (`pm.max_children`, default: the
   number of Swerve workers); a child idle for 30 seconds exits. Swerve opens a connection per
   request (a Unix socket: about 3 µs), so the kernel's accept queue is one FIFO queue for every
   worker and no child is tied to a worker. The children are forked from a master that never runs a
   request, so they share its opcache.
2. **A pool of `php -S` processes** when the extension isn't available: per worker (each its share
   of `--classic-workers`), HTTP on loopback, about 15% more CPU per request than PHP-FPM on
   WordPress.

### Who starts what

- **The extension's request server: one, started by Swerve's master, shared by all workers.** It
  takes control at PHP startup, so it is a fresh `php` process (`proc_open`), with its own ini
  (opcache size, `memory_limit`), never a fork of Swerve's master; one server means one opcache
  for every child. Order: the master forks the first worker, which evaluates `swerve.php` and
  reports the `ClassicMode` description; the master starts the server, waits until its socket
  listens, then lets the workers accept traffic. On a hard reload the master starts a new server,
  new connections go to it, and the old one drains and exits; on shutdown the master stops it.
- **The server's first process only forks and reaps.** It never runs PHP code, so it
  practically never dies; Swerve's master supervises only it (restarts it if it does, loudly).
- **A child that dies** (segfault, OOM killer; a fatal PHP error is not that, it ends in a 500 and
  the child serves on) is reaped by the first process, nothing more: its connection is gone with
  it. The Swerve worker sees the connection close: 502 if no response had started, otherwise the
  client connection closes. The next request is taken by another child, forked if none is free.
- **One queue for all workers:** an earlier design gave each worker a fixed share of persistent
  connections (the cap divided by the workers); measured on WordPress it queued requests in a busy
  worker while another's processes idled (p50 352 ms against 260 with one worker). Connections per
  request through one accept queue have no such split.
- **The `php -S` fallback is per worker:** each `php -S` has its own opcache anyway, so each worker
  grows its own pool, its share of `--classic-workers`, idle processes stopped after 30 seconds;
  the split between workers remains there.

### Protocol between Swerve and the request server

Frames: `[type: 1 byte][length: 4 bytes, big-endian][payload]`.

| Type | Direction | Payload |
|---|---|---|
| `HEAD` | Swerve → server | `serialize()` of `method`, `target`, `protocol`, `headers` (list of `[name, value]` pairs in order, duplicates kept), `remote` `[addr, port]`, `local` `[addr, port]`, `https`, `server_name`, `body` (bool) |
| `HEAD` | server → Swerve | `serialize()` of `status` and `headers` (list of `[name, value]`) |
| `BODY` | both | body bytes; length 0 ends the body. A request with `'body' => false` sends no BODY frames at all |
| `STOP` | server → Swerve | send no more request body (the script ended without reading it); Swerve sends the end-of-body frame at once, the server discards frames in flight until it |
| `ABORT` | Swerve → server | the client is gone (later: `connection_aborted()` as under FPM) |

Rules: one request per connection (the server also serves several in a row on one connection);
the server reads up to the request's end-of-body frame before the next request; a fatal
error still ends with a response `HEAD` (500 if nothing was sent) and the end frame; the response
never closes the connection (a `Connection: close` from the script or the client is Swerve's
business on the client side); response output is batched in 64 KiB frames, sent when full, on
`flush()` and at the end of the request.

Measured on WordPress (Ryzen 9950X3D, 2 server cores, c=32, JIT 1054 everywhere): the request
server runs at PHP-FPM's cost with ext-phasync loaded (16.5 ms CPU per request; FPM alone 16.2,
FPM with the extension 16.5). End to end, Swerve in front of it serves ~118 req/s against nginx +
FPM's ~123. Known follow-ups: serve mode skips the extension's coroutine hooks (≈1.5%, done, not
yet re-measured end to end), Swerve's front batches its client writes (≈1.5%).

## The same environment in all three modes

Code sees the same PHP environment whatever mode it runs in: `$_SERVER` with the same keys and
values (as PHP-FPM behind nginx sets them), `$_GET`/`$_POST`/`$_COOKIE`/`$_FILES`/`$_REQUEST`,
`php://input`, `header()`/`setcookie()`/`http_response_code()`, output buffering and `ob_*`,
sessions, the default time zone and locale, per request. Classic gets this from PHP's own request
lifecycle (the request server and `php -S` are SAPIs), and its whole process is one request, so
`\Scope` there is that request's root scope. Every request in a worker (Persistent, Async, and the
async routes of Classic and Persistent applications) runs inside ext-phasync's `sapi()`, always
(measured: ~1.8 µs per request on PHP 8.5 with the JIT), and `Scope` keeps its superglobals, time
zone and locale. Without the extension, application code is loaded through the plug
(phasync-babel translation to phasync-hooked replacements), which provides the same, slightly
inferior (database drivers in C stay blocking; preemption needs the extension).

Swerve's services (`Swerve::cache()`, `publish()`, `subscribe()`, `publishRaw()`,
`subscribeRaw()`, `claim()`) are available in all three modes. In Classic they travel as frames
on the request server connection, executed by the forwarding worker; `subscribe()` there holds its
process for as long as it waits. A Classic application doesn't load Swerve's Composer autoloader,
so the request server makes Swerve's client classes available itself.

## WebSockets and server-sent events per mode

- **Classic**: `WebSocket::setup(static function (WebSocket $ws) use (...) { ... })` (and
  `EventStream::setup()` for SSE). The function must be static (no `$this`); it is serialized with
  Serializor and runs in a Swerve worker, which keeps the connection; only its `use` values travel,
  as copies, and values that can't travel (PDO, resources) throw at `setup()`. Afterwards the
  request behaves as if the client had disconnected: `connection_aborted()` is true, output is
  discarded, PHP's own abort rules apply (including `ignore_user_abort()`). `setup()` must come
  before any output.
- **Persistent and Async**: return `new WebSocketResponse(function (WebSocket $ws) { ... })`
  (and the SSE equivalent). The function runs as a coroutine in the same process and outlives the
  request. In Persistent mode it keeps running while the worker takes the next requests (after the
  framework's cleanup), so it must not touch the framework's global state (facades, the container,
  the request's database connection): only its captured values and Swerve's and phasync's APIs.

## Not decided yet

- Swerve's services from Classic on the `php -S` pool: it has no channel back to Swerve (a
  loopback connection, or a documented limitation without the extension).
- Hibernating Classic requests (an idea only: a parked request would free its process).
- How the services' frames are numbered in the protocol (after the first Classic release).
- Retiring a worker on request (`Swerve::retire()`): not now.
- Windows/macOS for the request server (Linux first).
