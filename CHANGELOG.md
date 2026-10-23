# Changelog

## 0.2.0 (unreleased)

Breaking: `swerve.php` returns a mode instead of a `Swerve\RequestHandler`.

- Three modes: `ClassicMode::serve($publicRoot, $router)` runs every request from scratch with PHP's
  own request lifecycle, as PHP-FPM does (on ext-phasync's request server, or `php -S` processes
  without the extension); `PersistentMode::serve($publicRoot, $handler)` runs a booted application
  one request at a time per worker; `AsyncMode::serve($publicRoot, $handler)` runs requests as
  overlapping coroutines. `withAsync($pattern, $handler)` adds async routes to any mode.
- Every mode routes as nginx's `try_files $uri $uri/ /index.php` and serves static files itself,
  with `PATH_INFO`, directory index files and their `301`; a path with control characters or
  invalid UTF-8 is a `400`.
- Handlers: `function (ClientRequest $request): void`, PSR-7 closures, PSR-15 handlers and
  `function (): void` scripts; packages add more through `Swerve\Adapter` and
  `Swerve\Handlers::register()`. The PSR bridge, `WebSocketResponse` and `EventStreamResponse` are
  in core.
- Requests in a worker see `$_SERVER`, `$_GET`, `$_COOKIE` (and, for scripts, `$_POST`, `$_FILES`,
  `php://input`) as under PHP-FPM, and run inside ext-phasync's `sapi()`.
- Without a `swerve.php`, `swerve [dir] [router.php]` and `-t` serve a Classic application, as
  `php -S`. `--classic-workers` bounds Classic's processes, which run as PHP-FPM's `pm = ondemand`.
- Removed: `Swerve\RequestHandler`, `Swerve\StaticFiles`, `--public`, `--adapter`, the file
  argument, adapter discovery through Composer's `extra.swerve`, and `Swerve::claim()`; use
  `flock()`.

## 0.1.0 (2026-10-19)

The first release.
