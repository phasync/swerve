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
  `php://input`) as under PHP-FPM, and run inside `sapi()` (ext-phasync's, or phasync/hooked's
  through the plug without it, always, not only with the extension): `echo`, `header()` and the
  session are the request's own in every mode.
- Without a `swerve.php`, `swerve [dir] [router.php]` and `-t` serve a Classic application, as
  `php -S`. `--classic-workers` bounds Classic's processes, which run as PHP-FPM's `pm = ondemand`;
  with the extension, `connection_aborted()` in a Classic script works as under FPM.
- `Swerve::cache()`, `publish()`, `publishRaw()` and `subscribe()` work in Classic scripts too,
  through the service socket of the worker that forwarded the request.
- `WebSocket::setup()` and `ServerSentEvents::setup()` hand a Classic script's client over to a
  WebSocket or an event stream run by the worker; `WebSocketResponse::from()` and
  `EventStreamResponse::stream()` do the same for a Persistent or Async handler's PSR-7 response.
- Removed: `Swerve\RequestHandler`, `Swerve\StaticFiles`, `--public`, `--adapter`, the file
  argument, adapter discovery through Composer's `extra.swerve`, and `Swerve::claim()`; use
  `flock()`.

## 0.1.0 (2026-10-19)

The first release.
