# Getting started

## Install

Swerve and phasync, the coroutine library it runs on, are beta, so your project must allow
beta packages:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require phasync/swerve
```

Recommended, on Linux with PHP 8.3 to 8.5: the phasync extension, which swerve loads by itself
when it is installed. See [Production](production.md#phasync-ext) for what it changes.

```bash
composer require phasync/phasync-ext
vendor/bin/swerve --version   # swerve 0.1.0-beta1 (PHP 8.5.11, phasync 2.0.0-beta4, phasync-ext 0.5.0-beta1)
```

## A first application

Swerve serves a PHP file that returns a PSR-15 `RequestHandlerInterface`. By default it is
`swerve.php` in the current directory.

Without a framework:

```php
<?php // swerve.php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response('Hello, ' . ($request->getQueryParams()['name'] ?? 'World'), ['Content-Type' => 'text/plain']);
    }
};
```

`Swerve\Http\Message\Response` is swerve's own PSR-7 implementation, which any application may use; frameworks bring their own. With Slim (`composer require slim/slim slim/psr7`):

```php
<?php // swerve.php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Swerve\Swerve;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();                            // JSON and form bodies
$app->addErrorMiddleware(true, true, false, Swerve::log());  // error pages; errors in swerve's log
$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write('Hello, World');

    return $response;
});

return $app;
```

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

`--watch` reloads the workers one at a time: requests in flight finish on the old code, and
new ones go to the new code. A syntax error in a changed file is logged, and the old workers
go on serving until it is fixed.

Next: [How swerve runs your application](how-it-runs.md).
