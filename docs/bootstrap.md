# swerve.php is your application's bootstrap

The master never loads your application. Each worker requires `swerve.php` once, when it
starts, inside its event loop, and the file returns the `Swerve\RequestHandler`. Everything else the
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
    }
    while (true) {                                            // a stop ends it at a wait
        $app->cleanup();
        phasync::sleep(5);
    }
});

(new MemcachedServer(11211))->start();                        // a server of your own

return new Swerve\RequestHandler($app->handle(...));          // the handler that serves HTTP
```

[`examples/memcached`](../examples/memcached) is a complete server built this way.

## What works where

`Swerve::cache()` and `Swerve::publish()` go through the master, and the worker reads its
answers from the first turn of its event loop after `swerve.php` returns. A coroutine that
needs them before that waits for it, and carries on once the worker serves. `Swerve::claim()` and
`Swerve::subscribe()` work at any time.

| Your code runs | `cache()`, `publish()` | `claim()`, `subscribe()` |
|---|---|---|
| directly in `swerve.php`, while it loads | throw `LogicException` | work |
| in a coroutine started there (`phasync::go()`, `phasync::service()`) | wait for the worker to serve, then work | work |
| in a request, or in a connection of your own server | work | work |

Directly in `swerve.php` waiting could never end: the worker serves after the file returns, so
that is the one place with an immediate error. Move the call into a coroutine. This is the same
with and without phasync-ext. A subscription made while loading receives what any worker
publishes afterwards.

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

- When the worker stops, every coroutine you started gets a `Swerve\WorkerStoppingException`
  at its wait, at once, and the worker exits once they have ended: your server does not hold up
  a reload, and its clients reconnect, as they do after a crash or a recycle. Save or undo
  partial work in `phasync::finally()` or `phasync::shielded()`.
- `Swerve::draining()` turns true when the worker starts to stop.
- A claim is released as its handle goes, when the exception unwinds the job; a worker that
  exits or dies loses its claims.
- A reload stops every worker before starting new ones, so old and new code never run at the
  same time.

Next: [Requests and responses](requests-and-responses.md).
