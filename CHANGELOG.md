# Changelog

## Unreleased

### Added

- `--trusted-proxy=<ip|cidr|unix>` (HTTP mode, repeatable): for requests from a trusted peer,
  `REMOTE_ADDR`, `HTTPS`, `SERVER_PORT` and the host come from `X-Forwarded-For`, `-Proto` and `-Host`.
- With `Swerve::virtualize()`: `getallheaders()`, `apache_request_headers()` and
  `fastcgi_finish_request()`; `$_SERVER` has `SERVER_NAME`, `REQUEST_SCHEME`, `PHP_AUTH_USER`,
  `PHP_AUTH_PW`, `PHP_AUTH_DIGEST`, `AUTH_TYPE`, `SERVER_SOFTWARE`, `DOCUMENT_ROOT`, `SCRIPT_FILENAME`,
  `SCRIPT_NAME`, `PHP_SELF` and, over HTTP, `SERVER_ADDR` and `SERVER_PORT` (#28).
- Documented where virtualized requests differ from PHP-FPM: persistent connections, request state
  that is shared, `memory_limit` bounding all requests of a worker, `PHP_SAPI`, fiber stack size.

### Changed

- With `Swerve::virtualize()`, echoed output is sent in pieces of 8 KiB and at `flush()`, not one
  chunk per `echo`.

### Fixed

- `header()`, `setcookie()` and the session cookie were dropped when the handler returned a PSR-7
  response under `Swerve::virtualize()`; they are added to it (#27).
- `REMOTE_ADDR` of an IPv6 client was bracketed (`[::1]`); it is the bare address.

## 0.1.0-beta2 (2026-10-04)

### Added

- Lingering workers: a worker replaced by a recycle (`--max-memory`, `--max-requests`) keeps its
  upgraded connections (WebSockets, SSE, long polls) until the last client has left, or
  `--linger=<seconds>` (default 1800; 0 = drain as before) have passed. Subscriptions, cache and
  claims keep working meanwhile. A slot has at most three lingering workers; a recycle that
  would make a fourth waits, logged once. A reload or shutdown still drains with `--grace`, and
  ends the lingering too. `ServerInterface::drain()` takes a `$linger` argument.
- `Swerve::onShutdown($callback)` and `Swerve::awaitShutdown($timeout)`: told when the worker
  closes its connections (for a recycle, at the end of the lingering), so that a WebSocket can
  say goodbye. Callbacks are held weakly by the registering coroutine's context, so a request
  that ended is never kept alive and its callback never runs.
- `Swerve::virtualize()`: every request runs under phasync-ext's `virtualize()`, as under PHP-FPM:
  what the application echoes streams to the client as it is made, after the head it set up
  with `header()` and `http_response_code()`; a PSR-7 response the handler returns is sent as usual
  when nothing was echoed. Swerve owns it: adapters and applications do not set up `virtualize()`.
  The request's superglobals and `$_SESSION` are its own (#23).
- `StreamingResponderInterface`, implemented by the HTTP/1.1 and FastCGI responders: the head and
  the body are pushed as they are made.

### Fixed

- A drain with a WebSocket client that had stopped reading ended the worker with a `FiberError`
  (exit 255): the close frame no longer waits for the client (#24).

### Changed

- A recycle no longer drains the upgraded connections of the old worker within `--grace`: see
  lingering above. The master reserves four inboxes a slot instead of one.
- Without phasync-ext a worker serves at most 512 connections (half of `PHP_FD_SETSIZE`), down from
  960, because the application opens descriptors of its own, about one per client. With
  phasync-ext the limit is unchanged: the open-file limit less 64.
- Without phasync-ext, output outside a response (`echo`, `var_dump()`, ...) while a request is
  handled ends the worker with a message on standard error that names the output, the request, and
  the file and line, and the master starts a new worker. Before, it went to the terminal unnoticed.
  Output while no request is handled, such as `swerve.php` printing while it loads, is not an
  error: it goes to the worker's standard output. With phasync-ext nothing
  changes. See `docs/stray-output.md`.
