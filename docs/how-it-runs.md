# How swerve runs your application

## Processes

`vendor/bin/swerve` starts a **master process**, which starts the **workers**: one per CPU
core by default (`--workers`). The master never loads your application; it supervises:
it restarts a worker that dies, replaces a stuck one (the watchdog) or one that uses too
much memory (recycling), and reloads by restarting all workers.

Every worker loads `swerve.php` once, inside its event loop. The file is the application's
bootstrap: it sets things up, may start coroutines that run for the worker's whole life (a
subscriber, a periodic job) and servers of its own, and returns the mode
([details](bootstrap.md)). Then the worker serves HTTP on the same address as the others
(`SO_REUSEPORT`): the kernel hands each new connection to one of them. A connection stays with its
worker until it closes.

## Modes

| Mode | Per request | State between requests | Requests at a time |
|---|---|---|---|
| **Classic** | the scripts run from scratch, with PHP's own request lifecycle | none | one per PHP process |
| **Persistent** | the application, booted once per worker, runs its handler | kept | one per worker |
| **Async** | the same, as coroutines that overlap while they wait | kept and shared | many per worker |

Every mode routes requests the same way, as nginx's `try_files $uri $uri/ /index.php`: an async
route first, then a file under the public root (served by the worker itself: Content-Type,
ETag/Last-Modified, `HEAD`, ranges), then the application. Dotfiles, PHP source and anything
outside the public root are never served.

**Classic** runs the scripts in PHP processes of their own, outside the workers. With ext-phasync
that is the extension's request server, which the master starts once the first worker reports a
Classic application. It works as PHP-FPM with `pm = ondemand`: its children take requests from one
queue, in arrival order, whichever worker sent them, and run each with a fresh PHP state; a child
is forked when none is free, up to `--classic-workers` (the number of workers by default), and exits
after 30 seconds idle. The children share one opcache. A child that dies (a segfault, the OOM
killer) answers `502`; a fatal PHP error is a `500`, and the child serves on. Without the extension,
each worker runs its own pool of `php -S` processes, its share of `--classic-workers`.

**Persistent** runs the handler in the worker, one request at a time per worker: the next one waits
for the current one to finish. Async routes and static files are served meanwhile.

**Async** runs requests as coroutines, below.

## Coroutines

A worker serves many connections at once. Each connection runs in a
**coroutine** (a PHP Fiber, managed by [phasync](https://github.com/phasync/phasync)). Only one
coroutine runs at a time; another gets its turn when the running one **waits**: for the
network, for `phasync::sleep()`, for a message. So your handler code is ordinary sequential
PHP, and a worker still handles thousands of connections, as long as the code waits in ways
phasync knows about.

```php
// Other requests run while this one sleeps
phasync::sleep(0.5);

// Work in the background: runs after this coroutine waits or returns
phasync::go(function () {
    // ...
});
```

A handler runs in the connection's own coroutine, once per request, in a `Scope` of its own,
which the coroutines the request starts share: request-scoped state belongs in a `WeakMap` keyed
by `Scope::getCurrent()`, and is freed with the request. The request is also a nested
`phasync::run()`, whose frame (`phasync::frame()`) its coroutines join: a coroutine that fails
with nobody awaiting it is logged with the request, and the request goes on. The connection reads its next request once the handler has returned
and the coroutines it started have ended, but the client has the whole response before that, so work
after the response (`phasync::finally()`, a `go()`) costs it nothing. Work that must outlive the
request belongs in `phasync::service()`.

## The rule: never block a worker

A call that blocks the process, instead of letting phasync switch coroutines, stops
**every** request of that worker until it returns. Without the phasync extension that is:

- `sleep()` and `usleep()`: use `phasync::sleep()`
- database queries (PDO, mysqli), Redis clients, `curl`, `file_get_contents('https://...')`,
  `mail()`
- CPU-heavy work: a long loop, image processing, a big `json_decode()`

With [ext-phasync](production.md#ext-phasync) loaded, almost all of that waits as a coroutine
instead of blocking the process: sockets and TLS, MySQL through mysqli or PDO, `curl_exec()`
and curl-multi loops such as Guzzle's, `file_get_contents('https://...')`, pipes and child
processes, file and DNS functions, `sleep()` and `usleep()`. The
[extension's README](https://github.com/phasync/ext-phasync#readme) has the full list.
CPU-bound code still blocks, and so do clients with their own network code instead of PHP's
streams, such as PostgreSQL's libpq (pdo_pgsql, pgsql) and phpredis.

What that means in practice:

- A short blocking call is fine: a 1 ms SQLite query delays the other requests of that worker
  by 1 ms. Keep them short, and count them: 50 of them in one request is 50 ms for everyone.
- Long blocking calls need more workers (each worker is blocked alone), or belong in another
  process (a queue worker).
- A worker whose event loop is stuck for 30 seconds (`--watchdog`) is killed and replaced;
  the log says which request it was stuck in, and where.
- CPU-heavy work in a loop can let others run now and then: `phasync::yield()`.

## State: per worker, shared by its requests

Everything in PHP memory belongs to one worker, and lives from the worker's start to its end:

- **Static properties, globals, objects created when the application loads** are shared by
  all requests of that worker, including requests running at the same time. Never keep
  per-request data there. Caches, connection pools and precomputed data are fine.
- **Other workers do not see it.** A chat room's members, counters, caches: each worker has
  its own. To reach every worker, use [publish and subscribe](publish-subscribe.md); for
  state all workers need to read, use storage outside PHP (a database, Redis, a file).
- **A worker restarts** after a crash, a reload, or recycling (memory, `--max-requests`): its
  memory starts empty. Clients connected to it are disconnected by a crash or a reload and must
  reconnect (a browser's `EventSource` does); a
  recycled worker keeps its upgraded connections for up to `--linger` seconds, so they usually leave on
  their own.
- **Process-wide PHP state** (`setlocale()`, `date_default_timezone_set()`, ...) is the same for
  every request of a worker while it runs, like the others above. A framework running requests as
  coroutines rather than one process each can keep it as each request's own with
  a [`phasync::onScopeChange()` hook](../README.md#per-request-process-state-for-framework-authors).

### Shared state with SQLite

SQLite is the simplest store all workers can share. Open the connection in the application
file, outside the handler: each worker then gets its own connection (the master never loads the
application). WAL mode lets the workers read while one writes, and a busy timeout makes a
writer wait for another instead of failing:

```php
$db = new PDO('sqlite:' . __DIR__ . '/app.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=2000');
$db->exec('CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY, room TEXT, body TEXT, at INTEGER)');
```

Every worker runs this at start, at the same time: keep it idempotent (`IF NOT EXISTS`). A
SQLite query blocks the worker while it runs, usually well under a millisecond; a writer waiting
for the lock blocks it up to the busy timeout. For many concurrent writers, a database server
(MySQL, PostgreSQL) does better. Its queries block the worker the same way, for their duration,
unless ext-phasync is loaded and the database is MySQL: then they wait as a coroutine.

## The PHP environment

In every mode a request sees PHP's own request environment: `$_SERVER` as PHP-FPM behind nginx fills
it, `$_GET`, `$_COOKIE`, and for a `function (): void` handler also `php://input`, `$_POST` and
`$_FILES`. In Classic mode the PHP process is the request, so everything is as under PHP-FPM. In a
worker, every request runs inside `sapi()`, ext-phasync's or, without the extension, phasync/hooked's
equivalent: `echo`, `header()`, `setcookie()`, `http_response_code()`, output buffering and
`php://input` are the request's own (sessions too with the extension), and `$_GET`, `$_POST`,
`$_COOKIE` and `$_FILES`, the default time zone and the locale follow the request's `Scope`.
`$_SERVER` and `$_REQUEST` are filled at the start of each request but shared by the requests of a
worker: in Async mode read them before the request first waits. `$_SERVER` starts from what the
application put there while booting (its `.env` values), with the request's own values over it.
Without the extension, application code is translated by phasync/babel to call phasync/hooked's
replacements (the plug): blocking functions wait as coroutines, except database drivers written in C,
and nothing is preempted.

What still differs from PHP-FPM in Persistent and Async mode:

- **`exit()` and `die()` end the worker**, and every request it serves. The master starts a
  new one, but the others are cut off.
- **Uncaught exceptions** become a `500 Internal Server Error` for that request (when nothing was
  sent yet) and are logged with their trace; the worker goes on.
- **Memory leaks accumulate** over the worker's life. `--max-memory` (80 % of
  `memory_limit` by default) recycles a worker that grows too large: a new one starts, then
  the old one finishes its requests, and exits when its upgraded connections are gone.

## phasync in one page

The functions an application uses most (all static on `phasync`):

| | |
|---|---|
| `go(Closure $fn): Fiber` | Run `$fn` in a new coroutine. It starts when the current one waits or ends. An exception in it is logged by swerve, unless someone awaits it. |
| `await(Fiber $f, float $timeout = ...)` | Wait for a coroutine to finish; returns its result, or throws its exception. |
| `sleep(float $seconds = 0)` | Wait without blocking the worker. |
| `cancel(Fiber $f)` | Throw `phasync\CancelledException` into a waiting coroutine. |
| `channel($read, $write, int $buffer = 0)` | A channel between coroutines of the same worker. |
| `readable($stream, $timeout)` / `writable(...)` | Wait until a stream can be read or written, then use `fread()`/`fwrite()`. |
| `yield()` | Let other coroutines run, in CPU-heavy code. |

`phasync\TimeoutException` is thrown when a wait takes longer than its timeout.

Next: [swerve.php as bootstrap](bootstrap.md), then [Requests and responses](requests-and-responses.md).
