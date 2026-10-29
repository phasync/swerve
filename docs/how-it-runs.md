# How swerve runs your application

## Processes

`vendor/bin/swerve` starts a **master process**, which starts the **workers**: one per CPU
core by default (`--workers`). The master never loads your application: it listens, accepts every
connection, waits for its request to arrive, and hands it to the worker that asked first. It also
restarts a worker that dies, and reloads by replacing the workers. The workers are forked from it,
so they share its opcache and APCu memory.

A worker runs your `swerve.php` inside its event loop. Each `Swerve::accept()` asks the master for
a connection with a request waiting ([details](bootstrap.md)). After the response, a connection
whose client sends its next request at once is served on in the same worker; one that goes idle
goes back to the master, which hands its next request to whichever worker asks first.

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

The handler runs in the connection's own coroutine, once per request, in a `Scope` of its own,
which the coroutines the request starts share: request-scoped state belongs in a `WeakMap` keyed
by `Scope::getCurrent()`, and is freed with the request. The request is also a nested
`phasync::run()`, whose frame (`phasync::frame()`) its coroutines join: a coroutine that fails
with nobody awaiting it is logged with the request, and the request goes on.

Once the response is complete, the connection goes on without the request: it closes
(`Connection: close`) or takes the client's next request, while the request's code and the
coroutines it started run on in its `Scope` until they end. A response is complete when the
handler has returned, when its body has reached the `Content-Length` it declared, or at
`Swerve::finishRequest()`, which `fastcgi_finish_request()` and `litespeed_finish_request()` call
in a worker (Symfony's and Laravel's terminate step uses them). From then on the client is gone
for that code: `connection_aborted()` is true, and what it writes is dropped.

```php
echo json_encode(['queued' => true]);
fastcgi_finish_request();   // the client has its answer; the connection serves its next request
sendTheMails();
```

Work that must outlive the worker's next reload belongs in a queue.

## The rule: never block a worker

A call that blocks the process, instead of letting phasync switch coroutines, stops **every**
request of that worker until it returns. [ext-phasync](production.md#ext-phasync), which swerve
always loads, makes almost every waiting call wait as a coroutine: sockets and TLS, MySQL through
mysqli or PDO, `curl_exec()` and curl-multi loops such as Guzzle's,
`file_get_contents('https://...')`, pipes and child processes, file and DNS functions, `sleep()`
and `usleep()`. The [extension's README](https://github.com/phasync/ext-phasync#readme) has the
full list. What still blocks:

- clients with their own network code instead of PHP's streams, such as PostgreSQL's libpq
  (pdo_pgsql, pgsql) and phpredis;
- SQLite queries and cached local file reads, which return quickly;
- CPU-heavy work: a long loop, image processing, a big `json_decode()`. A coroutine running PHP
  code for 20 ms lets the others run, so it delays them but doesn't stop them.

A short blocking call is fine: a 1 ms query delays the other requests of that worker by 1 ms.
Count them: 50 of them in one request is 50 ms for everyone. Long blocking calls need more
workers, or belong in another process (a queue worker).

## State: per worker, shared by its requests

Everything in PHP memory belongs to one worker, and lives from the worker's start to its end:

- **Static properties, globals, objects created when the application loads** are shared by
  all requests of that worker, including requests running at the same time. Never keep
  per-request data there. Caches, connection pools and precomputed data are fine.
- **Other workers do not see it.** A chat room's members, counters, caches: each worker has
  its own. To reach every worker, use [publish and subscribe](publish-subscribe.md); for
  state all workers need to read, use storage outside PHP (a database, Redis, a file).
- **A worker restarts** after a crash or a reload: its memory starts empty. Clients connected to
  it are disconnected and must reconnect (a browser's `EventSource` does).
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
(MySQL, PostgreSQL) does better. MySQL's queries wait as a coroutine; PostgreSQL's block the
worker for their duration.

## Things that work differently from PHP-FPM

A request is PHP as under php-fpm: its own `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE`, `$_FILES`
and `$_SESSION`, `header()`, `setcookie()`, `http_response_code()` and `echo` for the response,
`php://input` for the body, and the shutdown functions it registers run at its end. What differs:

- **A `swerve.php` that loops shares the worker's memory** between its requests, including those
  running at the same time: statics and globals, as above. One that accepts once per run starts
  over clean for every request, as php-fpm does.
- **`exit()` and `die()` end the run of `swerve.php`**: the requests it serves at that moment are
  cut, and the worker starts it over. In a `swerve.php` that accepts once per run that is php-fpm's
  behaviour; in one that loops, return from the handler instead.
- **Uncaught exceptions** become a `500 Internal Server Error` for that request (when nothing was
  sent yet) and are logged with their trace; the worker goes on.
- **Memory leaks accumulate** over a run. A worker that reaches its `memory_limit` (a quarter of
  the memory, at most 4 GiB) dies and is replaced.

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
