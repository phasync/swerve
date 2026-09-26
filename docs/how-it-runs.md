# How swerve runs your application

## Processes

`vendor/bin/swerve` starts a **master process**, which starts the **workers**: one per CPU
core by default (`--workers`). The master never loads your application; it supervises:
it restarts a worker that dies, replaces a stuck one (the watchdog) or one that uses too
much memory (recycling), and does rolling reloads.

Every worker loads your application file once, inside its event loop, so the file may start
coroutines that run for the worker's whole life (a subscriber, a periodic job). Then it
serves HTTP on the same address as the
others (`SO_REUSEPORT`): the kernel hands each new connection to one of them. A connection
stays with its worker until it closes.

## Coroutines

A worker serves many connections at once. Each connection, and each request, runs in a
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

## The rule: never block a worker

A call that blocks the process, instead of letting phasync switch coroutines, stops
**every** request of that worker until it returns. Without the phasync extension that is:

- `sleep()` and `usleep()`: use `phasync::sleep()`
- database queries (PDO, mysqli), Redis clients, `curl`, `file_get_contents('https://...')`,
  `mail()`
- CPU-heavy work: a long loop, image processing, a big `json_decode()`

With [phasync-ext](production.md#phasync-ext) loaded, most of PHP's stream I/O no longer
blocks the process: inside a coroutine, `fread()`, `fwrite()`, `fgets()` and friends on
blocking streams, file reads, DNS lookups, `sleep()` and `usleep()` wait as a coroutine
instead. Database client libraries, `curl` and CPU-bound code still block.

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
  memory starts empty. Clients connected to it are disconnected and must reconnect (a
  browser's `EventSource` does; for WebSockets, reconnect in your client code).

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
(MySQL, PostgreSQL) does better; its queries block the worker the same way, for their duration.

## Things that work differently from PHP-FPM

- **Superglobals are not filled**: no `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE`, `$_FILES`.
  Use the PSR-7 request: `getQueryParams()`, `getCookieParams()`, `getServerParams()`,
  `getBody()`.
- **`header()`, `echo`, `setcookie()`, `http_response_code()` don't make the response.**
  Return a PSR-7 response. What you `echo` goes to swerve's terminal.
- **PHP's sessions (`session_start()`) don't work.** Use a PSR-7 session library, or your
  framework's.
- **`exit()` and `die()` end the worker**, and every request it serves. The master starts a
  new one, but the others are cut off.
- **Uncaught exceptions** become a `500 Internal Server Error` for that request and are
  logged with their trace; the worker goes on.
- **Memory leaks accumulate** over the worker's life. `--max-memory` (80 % of
  `memory_limit` by default) recycles a worker that grows too large: a new one starts, then
  the old one finishes its requests and exits.

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

Next: [Requests and responses](requests-and-responses.md).
