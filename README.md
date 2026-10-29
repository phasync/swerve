# Swerve

![SWERVE](swerve-logo.png)

**A PHP application server for connections that stay open.**

```php
<?php // swerve.php

while (Swerve\Swerve::accept('public/', function () {
    echo "Hello, World\n";
})) {
}
```

Swerve is a PHP application server, built on [phasync](https://github.com/phasync/phasync)
coroutines. A master process accepts the connections and hands each request to a worker; the
worker runs your `swerve.php`, which takes requests with `Swerve::accept()`. A request is PHP
as you know it: `$_GET`, `$_POST`, `$_SESSION`, `header()`, `echo`, `php://input`, each its own,
while the worker serves other requests at once. Your `swerve.php` chooses how: every request as it
comes, one at a time with the application kept loaded, or one per run, starting over clean as
under php-fpm.

> Swerve is at 0.x: options and APIs may still change before 1.0.

**[Documentation](docs/README.md)** · [Examples](examples/): a chat room over
[Server-Sent Events](examples/sse-chat) and one over [WebSockets](examples/websocket-chat).

## Why swerve

- **Connections that stay open.** Server-Sent Events, WebSockets and long polling from an ordinary
  request, raw connections after a `101`, and [publish/subscribe](docs/publish-subscribe.md)
  between workers, so live features need no separate Node or Go service.
- **Waiting is free.** While one request waits for the database or an API, the same worker serves
  others. Your code stays ordinary sequential PHP: no promises, no callbacks. ext-phasync makes the
  blocking calls of libraries you did not write (PDO, mysqli, curl, file and DNS functions) wait
  as a coroutine.
- **Isolated requests.** Each request runs in a `Scope` of its own, with its own superglobals,
  headers and output, which the coroutines it starts share.
- **Boot once, or start clean.** A `swerve.php` that loops keeps the framework, routes and
  connection pools between requests; one that accepts once per run gives each request a fresh
  process state, as php-fpm does, and still shares opcache and APCu with every worker.
- **Supervised.** A worker that dies is replaced. A reload starts new workers before the old ones
  stop, and the master's listener stays open throughout: no connection is refused.
- **Yours to own.** MIT, with no third-party dependencies beyond phasync and the PSR interfaces.
  See [the Ennerd philosophy](PHILOSOPHY.md).

## Getting started

Requirements: PHP 8.2 or later on Linux, with the `pcntl`, `posix` and `sockets` extensions, and `apcu` for
the shared cache, publish/subscribe and the container.
ext-phasync ships inside phasync, and swerve loads it by itself.

```bash
composer require phasync/swerve
vendor/bin/swerve -S public          # serve a directory as php -S does: WordPress, Laravel's public/
vendor/bin/swerve                    # run ./swerve.php
vendor/bin/swerve --watch            # during development: reload when a PHP file changes
```

`swerve.php` decides how many requests a worker serves at once:

```php
// every request as it comes, concurrently, with the application kept loaded
while (Swerve::accept('public/', $app->handle(...))) {
}

// one at a time, keeping the application between requests
while ($request = Swerve::accept('public/', $app->handle(...))) {
    phasync::await($request);
}

// one request per run of swerve.php, which starts over clean for the next (as under php-fpm)
phasync::await(Swerve::accept('wordpress/', 'wordpress/index.php'));
```

The first argument is a directory of static files, answered without the application. The
handler is a script's path (run as a fresh main script, its PHP files too), a closure without
parameters (superglobals in, `header()` and `echo` out), or a closure taking a PSR-7
`ServerRequestInterface` and returning a `ResponseInterface`. [Getting started](docs/getting-started.md)
goes through it step by step.

## What a request can do

- **Stay open.** `Swerve::upgrade()` takes the connection off the request for a closure that keeps
  it as long as it likes: a `Swerve\WebSocket`, a `Swerve\ServerSentEvents` stream, or the raw
  `Swerve\ClientRequest`. The worker serves other requests meanwhile. A PSR-7 handler's `101`
  response does the same. See [Realtime](docs/realtime.md).
- **Finish early.** The response is complete at its `Content-Length`, or at
  `Swerve::finishRequest()`, which `fastcgi_finish_request()` calls: the connection takes its next
  request while the request's code runs on.
- **Wait cheaply.** `phasync::sleep()`, `phasync::go()` and every phasync primitive work in a
  request; the worker serves others while it waits.

## Between workers

### Publish and subscribe

```php
Swerve::publish('chat', $message);

foreach (Swerve::subscribe('chat') as $message) {
    // every message published to 'chat', in any worker, from the moment subscribe() returned
}
```

Every message of every publisher has its place in one order, in a log the workers share in APCu.
Delivery is at most once, to the subscriptions that exist when it is published; a subscriber too
far behind gets a `SubscriberLagException`. [Publish and subscribe](docs/publish-subscribe.md)
has the rest.

### Shared cache

`Swerve::cache()` is a PSR-16 cache every worker shares, held in APCu.

```php
$cache = Swerve::cache();
$user  = $cache->get("user.$id") ?? load_user($id);
$cache->set("user.$id", $user, 60);
```

A get, set or delete is one APCu call: a write is seen by every worker at once. Values last as
long as the master; a reload keeps them. It shares `apc.shm_size` (256M by default, taking memory
only as it fills) with publish/subscribe, and when that is full APCu empties itself.

### Limiting a resource across workers

`Swerve::container()` is a PSR-11 container that caps how many instances of a service exist at
once in all workers together: MySQL accepts 151 connections by default, however many workers
there are.

```php
// swerve.php, before accept(): every worker adds the same services
Swerve::container()->add(\PDO::class, fn () => new \PDO($dsn, $user, $password), limit: 151);

$db = Swerve::container()->get(\PDO::class);            // this request's; waits while 151 are out
$db = Swerve::container()->tryGet(\PDO::class, $ok);    // null, $ok false, instead of waiting
```

A `Lifetime::Scoped` instance (the default) is shared by a request and its coroutines and given
back when the request has ended; `Transient` is one per `get()`; `Singleton` one per worker. An
instance holds its slot until it is garbage, and a worker that dies gives its slots back.

### One worker at a time

A job that must not run twice takes a lock with `flock()` on a file of its own; the kernel
releases it when the handle closes or the worker dies.

```php
$lock = fopen(sys_get_temp_dir() . '/myapp-nightly-report.lock', 'c');
if (flock($lock, LOCK_EX | LOCK_NB)) {
    work();
}
```

## Per-request process state, for framework authors

Superglobals, headers, output and the session are each request's own. Process-wide PHP state is
not: `setlocale()`, a static of your own. A `phasync::onScopeChange()` hook keeps such state as
each request's, so application code can go on using the plain functions:

```php
$locales = new WeakMap();   // per Scope
phasync::onScopeChange(function (Scope $new, ?Scope $old) use ($locales) {
    if (null !== $old) {
        $locales[$old] = setlocale(LC_ALL, 0);
    }
    setlocale(LC_ALL, $locales[$new] ?? 'C');
});
```

Register it once, before `accept()`. It runs before a coroutine of another scope than the last one
runs: never between a request's own coroutines. It runs in the event loop and must not wait.

## Running it

| Signal to the master | |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C) | Stop: the listener closes, the workers finish the requests in flight, then their WebSockets and event streams get a `WorkerStoppingException` (a WebSocket closes with 1001). What still runs after `--grace` (10 s) is killed. A second signal kills at once. |
| `SIGHUP` | Reload: each slot starts a worker on the code as it is now, and its old worker stops as above once the new one asks for its first request (a WebSocket closes with 1012: reconnect). A new worker that fails to start leaves the old one serving. |

- The master never loads your application. Workers are forked from it, so they share its opcache
  and APCu memory.
- swerve runs PHP as a long-running server: `memory_limit` is a quarter of the machine's memory
  (at most 4 GiB) per worker, `max_execution_time` is off, and opcache never checks timestamps:
  changed code arrives with a reload. `php -d name=value vendor/bin/swerve` sets anything else.
- It runs in the foreground, as systemd, Docker and supervisord expect, and logs to stderr (or
  `--log=<file>`): notices and up, such as reloads and workers that exit.

[Command line](docs/command-line.md) has every option, and [Production](docs/production.md) sizing,
systemd, Docker, nginx and TLS.
