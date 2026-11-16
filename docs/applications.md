# Running an existing application

An application that runs under php-fpm behind nginx runs under swerve as it is: no adapter, no
change to its code. Point swerve at the directory nginx would serve:

```bash
composer require phasync/swerve:^0.2
vendor/bin/swerve -S public --http=0.0.0.0:8080
```

or, as a `swerve.php` (the same thing, with room for [more](bootstrap.md)):

```php
<?php // swerve.php

phasync::await(Swerve\Swerve::accept('public/', 'public/index.php'));
```

Each request runs as under php-fpm: a fresh main script, its own superglobals, session,
`header()` and `echo`. Requests are routed as nginx's usual PHP configuration
(`try_files $uri $uri/ /index.php?$args` with `fastcgi_split_path_info`) routes them:

- a file is served as it is, and a directory by its `index.php`, else its `index.html`;
- `/a/b.php/more` runs `a/b.php`, with `PATH_INFO` `/more`;
- everything else runs the front controller, `public/index.php`;
- doubled slashes are merged, and names starting with a dot are never served (`.well-known/` is).

## What you set up as for php-fpm

- **The document root.** The directory with the front controller: `public/` for Laravel and
  Symfony, `web/` for Drupal and CakePHP's `webroot/`, the install directory for WordPress,
  Joomla, phpBB and the others below.
- **Writable directories.** Run swerve as the user that owns them (`storage/`, `var/`, uploads).
- **The site's URL.** An application that stores its own URL or trusted hosts (WordPress's
  `siteurl`, Moodle's `wwwroot`, Nextcloud's `trusted_domains`, Drupal's
  `trusted_host_patterns`) must name the address clients use, port included. Behind a proxy,
  configure the application's trusted proxies as you would for nginx: see
  [Production](production.md).
- **php.ini.** swerve reads php.ini, including `output_buffering`, as php-fpm does. It does not
  read `.user.ini` files: move what they set (Moodle's `max_input_vars`, Nextcloud's upload
  limits) into php.ini, or pass it as `php -d name=value vendor/bin/swerve`.
- **More than one worker.** An application that requests itself (WordPress's cron and Site
  Health, Nextcloud's background checks) needs a second worker free to answer: keep the default
  of one per core, or at least `--workers=2`.

## Verified applications

swerve's acceptance suite installs each of these, crawls it logged out and logged in, submits its
forms, and compares every response, header and log line with the same site under php-fpm 8.4
behind nginx.

| Application | Version | Document root | Differences from php-fpm |
|---|---|---|---|
| CakePHP | 5.4 | `webroot/` | none |
| CodeIgniter | 4.7 | `public/` | none |
| Drupal | 11.4 | `web/` | see [Drupal](#drupal) |
| Joomla | 5.4 | the install directory | none |
| Laravel | 13 | `public/` | none |
| Matomo | 5.14 | the install directory | none |
| MediaWiki | 1.43 | the install directory | article URLs, see [MediaWiki](#mediawiki) |
| Moodle | 4.5 | the install directory | none (`max_input_vars` in php.ini) |
| Nextcloud | 35 | the install directory | none |
| phpBB | 3.3 | the install directory | none |
| PrestaShop | 8.2 | the install directory | see [PrestaShop](#prestashop) |
| Symfony (demo app) | 8.1 | `public/` | none |
| WordPress | 7.1 | the install directory | none |

### Drupal

Drupal renders parts of a page in Fibers of its own, and takes that code path whenever it runs
inside a Fiber. Under swerve every request runs in one, so Drupal batches its work as it does
inside its placeholders. Pages are the same, but on some pages Drupal combines its CSS and JS
files in another order than under php-fpm.

### MediaWiki

MediaWiki picks its article URLs by `PHP_SAPI`: `?title=Page` under php-fpm, `/index.php/Page`
under any other server, swerve's included. Both work under swerve. Set `$wgUsePathInfo` in
`LocalSettings.php` to choose one.

### PrestaShop

PrestaShop keeps objects in static properties (its `Context`, with the cookie), and writes the
cookie from their destructors when PHP shuts down. Under php-fpm that runs before the headers are
sent; under swerve the worker lives on, and those objects are destroyed after the response, too
late for their `Set-Cookie`. Pages are the same, but the PrestaShop cookie that php-fpm sends
with most responses is not sent, so what PrestaShop stores in it during a request is lost. Every
application server that keeps PHP running between requests has this limit.

## Boot once

`swerve -S` starts each request fresh, as php-fpm does: the framework boots for every request.
A `swerve.php` that loops keeps the application loaded in the worker instead, and each request
only runs its handler. Measured on one machine (2 workers, `wrk -c8`, opcache on, production
settings, the same route both ways):

| Framework | Fresh per request (`-S public`) | Booted once (`swerve.php`) |
|---|---|---|
| Laravel 13 | 280 requests/s | 1,193 requests/s |
| Symfony 8.1 | 599 requests/s | 3,870 requests/s |
| Slim 4.15 | 1,071 requests/s | 6,617 requests/s |

Booting once needs a framework that resets its own request state between requests. The three
below do, and their `swerve.php` is in [`examples/frameworks`](../examples/frameworks). Each
serves one request at a time per worker:

```php
while ($request = Swerve::accept('public/', $handler)) {
    phasync::await($request);
}
```

A `while (Swerve::accept(...)) {}` loop without the `await` runs a worker's requests at the
same time, on the one application, as soon as one of them waits for I/O. None of these frameworks
is written for that, so keep the `await`, and get concurrency from more workers.

Your own code needs the same care as under FrankenPHP's worker mode, RoadRunner or Octane: a
static property or global that holds request data keeps it for the next request. Keep request
state in the framework's request-scoped services. A request's `ini_set()` is undone before the
next request; what `swerve.php` sets before its loop stays.

### Laravel

Laravel resets its application between requests with Laravel Octane's listeners, so install
Octane for them. Its servers and `octane:install` are not used:

```bash
composer require laravel/octane
```

```php
<?php // swerve.php

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Swerve\Swerve;

$client = new FrankenPhpClient();
$worker = new Worker(new ApplicationFactory(__DIR__), $client);
$worker->boot();

while ($request = Swerve::accept('public/', function () use ($worker, $client) {
    $_SERVER['LARAVEL_OCTANE'] = 1;
    [$request, $context] = $client->marshalRequest(new RequestContext());
    $worker->handle($request, $context);
})) {
    phasync::await($request);
}
```

Octane's client for FrankenPHP reads the request from the superglobals and sends the response
with `header()` and `echo`, which is what a swerve request is, so it serves as it is. Each request
gets a fresh copy of the booted application, and Octane's listeners flush the auth, session and
other state as they do under Octane. `LARAVEL_OCTANE` tells packages that check it that they run
under Octane.

### Symfony

The kernel resets every service tagged `kernel.reset` (any `ResetInterface`) between main
requests, which is what FrankenPHP's and RoadRunner's worker modes rely on:

```php
<?php // swerve.php

use App\Kernel;
use Swerve\Swerve;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

(new Dotenv())->bootEnv(__DIR__ . '/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);

while ($request = Swerve::accept('public/', function () use ($kernel) {
    $request  = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
})) {
    phasync::await($request);
}
```

`$response->send()` calls `fastcgi_finish_request()`, so the client has its response before
`terminate()` runs.

### Slim, Mezzio and other PSR-15 applications

A PSR-15 application's `handle()` takes the `ServerRequestInterface` and returns the response,
which is the handler `Swerve::accept()` takes. Build the application in a file of its own, so the
same application runs under php-fpm or `swerve -S` through `public/index.php`:

```php
<?php // swerve.php

use Swerve\Swerve;

$app = require __DIR__ . '/app.php';   // returns the Slim\App, or Mezzio's Application

while ($request = Swerve::accept('public/', $app->handle(...))) {
    phasync::await($request);
}
```

The request is made from the superglobals and the body as under php-fpm, so body-parsing
middleware reads form and JSON bodies as it does there. Echo nothing in a PSR-7 handler: write to
the response body.

### Other applications

WordPress, Drupal, Joomla, CakePHP, CodeIgniter, Yii, Laminas MVC and the other applications above
have no way to reset their request state between requests that a `swerve.php` could call, so
run them fresh per request, with `swerve -S`. They are verified that way.
