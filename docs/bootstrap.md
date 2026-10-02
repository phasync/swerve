# swerve.php is your application's bootstrap

The master never loads your application. Each worker requires `swerve.php` once, when it
starts, inside its event loop, and the file returns the request handler. Everything else the
file does happens in that worker, once per worker, before the handler serves its first request:

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
    while (!($claim = Swerve::claim('cleanup')->acquire(timeout: 5))) {
        if (Swerve::draining()) {
            return;
        }
    }
    while (!Swerve::draining()) {
        $app->cleanup();
        phasync::sleep(5);
    }
    $claim->release();
});

(new MemcachedServer(11211))->start();                        // a server of your own, last

return $app;                                                  // the PSR-15 handler that serves HTTP
```

[`examples/memcached`](../examples/memcached) is a complete server built this way.

## What works where

`Swerve::cache()` and `Swerve::publish()` need the worker to serve, which it does from the first
turn of its event loop after `swerve.php` returns. `Swerve::claim()` and `Swerve::subscribe()`
work at any time.

| Your code runs | `cache()`, `publish()` | `claim()`, `subscribe()` |
|---|---|---|
| directly in `swerve.php`, while it loads | throw `LogicException` | work |
| in a coroutine, before its first wait | throw `LogicException` | work |
| in a coroutine, after its first wait (`phasync::sleep()`, a stream, a message) | work | work |
| in a request, or in a connection of your own server | work | work |

This is the same with and without phasync-ext. A wait inside `swerve.php` lets the coroutines
started before it run, before the worker serves. `phasync::sleep()` waits, and with phasync-ext
so do the blocking calls it makes cooperative (`usleep()`, MySQL, curl). So put the coroutines
and servers last in the file, and let a coroutine that needs the cache wait first. A subscription
made while loading receives what any worker publishes afterwards.

## One copy per worker

Coroutines and servers started in `swerve.php` exist once in every worker: with 8 workers, a
subscriber runs 8 times, and a periodic job runs 8 times unless it takes a
[`Swerve::claim()`](../README.md#claims) first, as above. Nothing a worker keeps in memory is
seen by the others (see [state](how-it-runs.md#state-per-worker-shared-by-its-requests)); the
cache, messages and claims are what the workers share.

An extra listening socket needs `SO_REUSEPORT`, so that every worker can listen on the same
port: the kernel hands each new connection to one worker, and it stays there. Keep what the
connections must share in `Swerve::cache()`. Or let one worker own the port: take a claim, and
listen only while you hold it.

## Restarts and reloads

- A worker exits once its HTTP servers have drained. That ends the coroutines you started and
  closes your servers' connections at once: your server does not hold up a reload, and its
  clients reconnect, as they do after a crash or a recycle.
- `Swerve::draining()` turns true when the worker starts to drain. Stop accepting then, and
  finish the work in progress.
- Draining does not release a claim. A job that holds one releases it when
  `Swerve::draining()`, so that its successor is not kept waiting. A worker that exits or dies
  loses its claims.
- During a rolling reload, a worker with the old code and one with the new run at the same
  time: two copies of a job, both listening on the port.

Next: [Requests and responses](requests-and-responses.md).
