# swerve.php is your application's bootstrap

The master never loads your application. A worker runs `swerve.php` inside its event loop, and
the file takes requests with `Swerve::accept()`. A `swerve.php` that loops runs once for the
worker's whole life, so everything it does before the loop happens once per worker:

- configuration, dependency injection, database connections;
- coroutines that live as long as the worker: subscribers, periodic jobs
  (`phasync::go()`, `phasync::service()`);
- servers of your own: extra listening sockets, accepted in coroutines next to the HTTP server.

```php
<?php // swerve.php

use Swerve\Swerve;

$app = new App(require __DIR__ . '/config.php');              // setup, once per worker

phasync::go(function () use ($app) {                          // drops what other workers changed
    foreach (Swerve::subscribe('changed') as $key) {
        $app->forget($key);
    }
});

phasync::go(function () use ($app) {                          // one worker at a time runs the job
    $lock = fopen(sys_get_temp_dir() . '/myapp-cleanup.lock', 'c');
    while (!flock($lock, LOCK_EX | LOCK_NB)) {
        phasync::sleep(5);
    }
    while (true) {                                            // a stop ends it at a wait
        $app->cleanup();
        phasync::sleep(5);
    }
});

(new MemcachedServer(11211))->start();                        // a server of your own

while (Swerve::accept('public/', $app->handle(...))) {         // the requests
}
```

The run ends once `swerve.php` and every coroutine it started have ended; the worker then runs it
again. So a `swerve.php` that accepts once per run (as `swerve -S` does) starts over clean for
every request, and a coroutine it starts keeps that run, and the next request, waiting until it
ends.

[`examples/memcached`](../examples/memcached) is a complete server built this way.

## What works where

`Swerve::cache()`, `Swerve::publish()` and `Swerve::subscribe()` work anywhere: directly in
`swerve.php` while it loads, in coroutines started there, and in requests. They use APCu, which
every worker shares from its start. A subscription made while loading receives what any worker
publishes afterwards.

## One copy per worker

Coroutines and servers started in `swerve.php` exist once in every worker: with 8 workers, a
subscriber runs 8 times, and a periodic job runs 8 times unless it takes a
[lock](../README.md#one-worker-at-a-time) first, as above. Nothing a worker keeps in memory is
seen by the others (see [state](how-it-runs.md#state-per-worker-shared-by-its-requests)); the
cache and messages are what the workers share.

An extra listening socket needs `SO_REUSEPORT`, so that every worker can listen on the same
port (with phasync/net: `phasync\Net\serve($address, ['so_reuseport' => true])`): the kernel hands each new connection to one worker, and it stays there. Keep what the
connections must share in `Swerve::cache()`. Or let one worker own the port: take a lock, and
listen only while you hold it.

## Restarts and reloads

- When the worker stops, its requests in flight finish; then every coroutine still running gets
  a `Swerve\WorkerStoppingException` at its wait, and a second to end: your server does not hold
  up a reload, and its clients reconnect, as they do after a crash. Save or undo partial work in
  `phasync::finally()` or `phasync::shielded()`. `Swerve::onShutdown()` callbacks run first.
- A lock (`flock()`) is released as its handle goes, when the exception unwinds the job; a worker
  that exits or dies loses its locks.
- A reload starts each new worker before its old one stops, so old and new code run side by side
  for a moment: a change to shared state (the cache, a database) must suit both.

Next: [Requests and responses](requests-and-responses.md).
