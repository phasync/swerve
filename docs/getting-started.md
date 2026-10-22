# Getting started

## Install

```bash
composer require phasync/swerve
```

Recommended, with PHP 8.3 or later: the phasync extension, which ships with phasync. Start swerve
with `--ext`, or install it with `pie install phasync/ext-phasync`. See [Production](production.md#ext-phasync) for what it changes.

```bash
vendor/bin/swerve --ext --version   # swerve 0.2.0 (PHP 8.5.12, phasync 2.0.4, ext-phasync 2.0.2)
```

## An existing PHP application

In the directory with its `index.php`, as with `php -S`:

```
> vendor/bin/swerve public
2026-10-22 10:18:22.42 0 Classic (the extension's request server, up to 32 processes)
2026-10-22 10:18:22.61   swerve 0.2.0 serving /srv/app/public through /srv/app/public/index.php on http://127.0.0.1:8080 with 8 workers
2026-10-22 10:18:22.71 3 GET / 200 3.0ms
```

This is Classic mode: every request runs the application's scripts from scratch, with PHP's own
request lifecycle, as under PHP-FPM behind nginx's `try_files $uri $uri/ /index.php`. Files under
`public/` are served as they are, an existing `.php` file runs, and every other path runs
`public/index.php`. `swerve router.php` and `swerve public router.php` name another router.

Every log line has the time, the worker (its slot number; blank for the master process), and the
message. Ctrl+C stops swerve: requests in flight get a `503`.

## swerve.php

An application configures swerve in `swerve.php`, which returns its mode:

```php
<?php // swerve.php

use Swerve\{ClassicMode, PersistentMode, AsyncMode, ClientRequest};

// Classic, with a WebSocket endpoint that runs in swerve's workers
return ClassicMode::serve('public')
    ->withAsync('/ws/', function (ClientRequest $request): void { /* ... */ });

// Persistent: boot once per worker, one request at a time
return PersistentMode::serve('public', require __DIR__ . '/bootstrap/app.php');

// Async: boot once per worker, requests overlap while they wait
return AsyncMode::serve('public', $app);
```

Paths are relative to `swerve.php`'s directory. A handler is a PSR-15 request handler, a
`function (ServerRequestInterface $request): ResponseInterface`, swerve's native
`function (ClientRequest $request): void`, or a `function (): void` that reads the superglobals and
echoes like a classic script; framework adapters add their own types. A handler that waits and
writes is a stream:

```php
<?php // swerve.php

use phasync\IOException;
use Swerve\{AsyncMode, ClientRequest};

return AsyncMode::serve(null, function (ClientRequest $request): void {
    $request->sendResponseHeaders(200, ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache']);
    $request->flush();                       // the head goes out now
    try {
        for ($n = 1; ; ++$n) {
            $request->write("data: $n\n\n");
            phasync::sleep(1);               // other requests run meanwhile
        }
    } catch (IOException) {
        // the client left
    }
});
```

`swerve.php` runs once in each worker process, when the worker starts: code outside the handler
(creating the app, reading configuration) runs once per worker, not once per request. It may also
start background coroutines and servers of your own, see [swerve.php as bootstrap](bootstrap.md).
In Classic mode it runs only in swerve's workers, for the async routes: the application's scripts
run in processes of their own.

[Requests and responses](requests-and-responses.md) is the reference for the `ClientRequest`.

## During development

```bash
vendor/bin/swerve --watch                 # reload the workers when a PHP file changes
vendor/bin/swerve -w 1 -v                 # one worker, and more log
```

`--watch` restarts all workers when a file changes: the old ones stop (requests in flight get a
`503`), then new workers start on the new code. A syntax error in a changed file is logged, and the
workers keep failing to start until it is fixed.

Next: [How swerve runs your application](how-it-runs.md).
