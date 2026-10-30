# Getting started

## Install

```bash
composer require phasync/swerve:^0.2@RC
```

0.2 is a release candidate: without `@RC`, Composer installs 0.1, which works differently.

Swerve runs on the phasync extension, which ships with phasync: `vendor/bin/swerve` loads it by
itself, or uses one installed with `pie install phasync/ext-phasync`. See
[Production](production.md#ext-phasync) for what it does.

## A directory, as php -S serves it

```bash
vendor/bin/swerve -S public
```

serves the files in `public/`, runs its PHP files, and sends every other request to
`public/index.php`, as `php -S localhost:8080 -t public` does. Each request runs as under
php-fpm: a fresh main script, its own superglobals, `header()` and `echo` for the response. A
WordPress site runs this way unchanged.

## A first swerve.php

For more than that, write a `swerve.php`. A worker runs it, and it takes requests with
`Swerve::accept()`: the directory of static files, and the handler for the rest.

```php
<?php // swerve.php

use Swerve\Swerve;

while (Swerve::accept('public/', function () {
    header('Content-Type: text/plain');
    echo 'Hello, ', $_GET['name'] ?? 'World', "\n";
})) {
}
```

Each call takes one request and serves it in a coroutine of its own, so the loop serves every
request as it comes, many at once. Code before the loop (creating the app, reading configuration)
runs once per worker, and the application stays loaded between requests. `swerve.php` may also
start background coroutines and servers of its own: see [swerve.php as bootstrap](bootstrap.md).

The handler is one of:

- a closure without parameters, as above: superglobals and `php://input` in, `header()`,
  `http_response_code()` and `echo` out;
- a closure taking a PSR-7 `ServerRequestInterface`, returning a `ResponseInterface`;
- a script's path, run as a fresh main script for each request, its variables globals:
  `Swerve::accept('public/', 'public/index.php')`.

A request that waits lets the worker serve others meanwhile, so a response may take as long as it
likes. One that stays open hands its connection to a closure with `Swerve::upgrade()`:

```php
while (Swerve::accept(null, function () {
    if ('/events' === $_SERVER['REQUEST_URI']) {
        Swerve::upgrade(static function (Swerve\ServerSentEvents $events): void {
            for ($n = 1; ; ++$n) {
                $events->send((string) $n);   // throws once the client left
                phasync::sleep(1);            // other requests run meanwhile
            }
        });

        return;
    }
    echo "Hello\n";
})) {
}
```

[Requests and responses](requests-and-responses.md) is the reference.

## Run it

```bash
vendor/bin/swerve                         # ./swerve.php on http://127.0.0.1:8080, a worker per core
vendor/bin/swerve --watch                 # during development: reload when a PHP file changes
vendor/bin/swerve --workers=1 app.php     # one worker, another file
```

swerve logs notices and up: a reload, a worker that exited, an error of the application.
Ctrl+C stops it: requests in flight finish first.

`--watch` reloads when a file changes: new workers start on the new code, and each old one stops
once its replacement is ready, after finishing its requests. A syntax error in a changed file is
logged, and the old workers serve until it is fixed.

Next: [How swerve runs your application](how-it-runs.md).
