# Getting started

## Install

Swerve and phasync, the coroutine library it runs on, are beta, so your project must allow
beta packages:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve
```

Recommended, on Linux with PHP 8.2 to 8.5: the phasync extension, which ships inside phasync.
Enable it with `"extra": {"phasync": {"ext": true}}` in your `composer.json`, or start swerve with
`--ext`. See [Production](production.md#phasync-ext) for what it changes.

```bash
vendor/bin/swerve --ext --version   # swerve 0.1.0-beta4 (PHP 8.5.11, phasync 2.0.0-beta7, phasync-ext 0.5.0-beta5)
```

## A first application

Swerve serves a PHP file that returns a `Swerve\RequestHandler` wrapping a closure. By default it
is `swerve.php` in the current directory. The closure runs once for each HTTP exchange, with a
`Swerve\ClientRequest`:

```php
<?php // swerve.php

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("Hello, World\n");
});
```

Reading the request is up to the handler: the method, target and headers are getters, and the
body is read with `read()`.

```php
<?php // swerve.php

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    parse_str((string) parse_url($request->getTarget(), PHP_URL_QUERY), $query);

    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write('Hello, ' . ($query['name'] ?? 'World') . "\n");
});
```

The exchange lasts as long as the closure does, so a handler that waits and writes is a stream:

```php
<?php // swerve.php

use phasync\IOException;

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
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

A framework is served through an adapter that turns a `ClientRequest` into the framework's own
request and response. [Requests and responses](requests-and-responses.md) is the reference for the
`ClientRequest`.

The file is loaded once in each worker process, when the worker starts: code outside the
handler (creating the app, reading configuration, connecting to a database) runs once per
worker, not once per request. It is the application's bootstrap: it may also start background
coroutines and servers of your own, see [swerve.php as bootstrap](bootstrap.md).

## Run it

```
> vendor/bin/swerve
2026-09-26 17:00:30.12    swerve 0.1.0 serving ./swerve.php on http://127.0.0.1:8080 with 8 workers
2026-09-26 17:00:31.25 3 GET / 200 1.2ms
```

Every line has the time, the worker (its slot number; blank for the master process), and the
message. Ctrl+C stops swerve after the requests in flight finish.

During development:

```bash
vendor/bin/swerve --watch                 # reload the workers when a PHP file changes
vendor/bin/swerve --watch --public=public # also serve the files in public/
vendor/bin/swerve -w 1 -v                 # one worker, and more log
```

`--watch` restarts all workers when a file changes: requests in flight finish on the old code,
then new workers start on the new code. A syntax error in a changed file is logged, and the
workers keep failing to start until it is fixed.

Next: [How swerve runs your application](how-it-runs.md).
