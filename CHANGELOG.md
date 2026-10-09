# Changelog

## Unreleased

### Added

- `-t, --docroot=<dir>` and the file argument reach an adapter's entry, by name and as absolute
  paths, when given: `entry(string $appDir, ?string $docroot = null, ?string $file = null)`. An
  adapter for plain PHP files can so take the shape of `php -S` (`swerve -t public router.php`).
  An entry without such a parameter stops swerve (exit code 2) when it is given; `-t` with the
  `swerve` adapter is a usage error that points at `--public`; a `-t` that is no directory, or a
  file argument that does not exist, stops swerve at start.

### Changed

- swerve works with every major version of the PSR packages it uses, and its `composer.json`
  says so: `psr/log` `^1.0 || ^2.0 || ^3.0`, `psr/simple-cache` `^1.0 || ^2.0 || ^3.0`,
  `psr/http-message` `^1.0 || ^2.0` (now required directly, as `Psr\RequestBody` implements it).
  An application that bundles psr/log 1 (MediaWiki) or another major gets it from its own
  Composer, with swerve in the same `vendor/`. `Util\Logger::log()` takes an untyped
  `$message`, and `Cache`'s methods untyped keys, iterables and TTLs, as the 1.0 interfaces
  declare them, checked inside instead: a bad key or iterable throws `CacheKeyException`, a bad
  TTL a `TypeError`. `php tests/psr-versions.php` loads every class against the lowest, middle
  and highest allowed majors.
- Adapters are looked up in the current directory, also when a file argument is given (it was
  that file's directory). Giving a file with an adapter other than `swerve` passes it to the
  adapter instead of being an error. The positional argument is `[file]` in `--help`.
- The JIT runs as `opcache.jit=1054`, tracing without register allocation, instead of `tracing`.
  PHP 8.5's tracing JIT with register allocation can leave a variable undefined after an exception
  from an internal call (`Fiber::resume()`) is caught in the same function; phasync's event loop
  hit it as workers stopped. An explicit `-d opcache.jit=` still wins.
- A worker stops at once: on a shutdown, reload or the end of a recycle's lingering, every
  coroutine, requests in flight included, gets a `Swerve\WorkerStoppingException` at its wait,
  and the worker exits once they have cleaned up (`phasync::finally()`, `phasync::shielded()`),
  a second before `--grace` at most. swerve ends its own protocols shielded: a `503` for a
  response not yet started, a `Swerve\WebSocket` closes with 1001, or 1012 on a reload, idle
  connections close. Requests no longer finish during a drain, and a `101` connection's `read()`
  throws instead of returning `''`. Clients retry, as after any server's death.
- A worker's signals go through phasync: it waits for an outside `SIGTERM` with
  `phasync::signal()`, and logs where it is stuck (`SIGQUIT`) or ends itself when its master died
  (`SIGALRM`) with `phasync::onSignal()`, so application coroutines may wait for these signals too.
  Requires phasync `dev-main` until its next release.

- swerve overrides `php.ini`'s shared-hosting settings and its opcache configuration, with one
  restart at startup: no `memory_limit` (bound memory with the container; `--max-memory` now needs
  a size to recycle workers), no `open_basedir`/`disable_functions`, no `auto_prepend_file` or
  `opcache.preload`, opcache and the JIT on for the command line with large caches, and no
  timestamp checks (changed code arrives only through a reload). `php.ini` still loads the
  extensions; an explicit `-d` still wins. See docs/production.md.
- A reload (`--watch`, `SIGHUP`, `SIGUSR2`) stops every worker, then the master restarts swerve in
  place (same PID, the operator's command line): the application, swerve, phasync, `php.ini`, what
  integration files register and opcache are all fresh, and old and new code never run side by
  side. Rolling reloads are gone; the cache starts empty after a reload. The previous
  `opcache_reset()` never took effect under swerve: it waits for a request boundary the master
  never reaches, and meanwhile stops caching.

### Added

- `Swerve\WorkerStoppingException` (a phasync `ShutdownException`) with `StopReason` (`Shutdown`,
  `Reload`, `Recycle`), see Changed.

- `Swerve::ini()` and `Swerve::onWorkerStart()`: a package integrates with swerve from a file in its
  composer.json `files`, loaded as the master starts. `ini()` sets php.ini settings for every swerve
  process (applied by the startup restart; an explicit `-d` still wins); `onWorkerStart()` runs code
  in every worker before the application loads. Calls after startup change nothing.

- `Swerve::onRequestSwitch(resume, suspend)`: a framework running requests as coroutines of one
  worker can keep process-wide PHP state (`setlocale()`, `date_default_timezone_set()`,
  `mb_internal_encoding()`, ...) as each request's own. `resume($request)` runs just before a
  coroutine of `$request` runs, but only when a coroutine of a *different* request ran last;
  `suspend($request)` runs just before a different request's coroutine is about to run; a
  request's own `phasync::go()` children call neither. Implemented by the request's phasync
  context (`phasync\Context\SwitchAwareInterface`), entered eagerly only once something is
  registered - nothing registered costs nothing. See the README, Per-request process state.

### Fixed

- NUMA pinning no longer overrides the CPUs the operator allowed (`taskset`, a cgroup cpuset,
  `--cpuset-cpus`): a worker pins to its node's share of them, and not at all when they lie on one
  node. Before, each worker took its whole node, whatever the affinity it inherited (#41).

## 0.1.0-beta6 (2026-10-06)

### Added

- `Swerve\Psr\RequestBody` and `Swerve\Psr\FormBody`, moved here from swerve-psr15 so every framework
  adapter (swerve-psr15, Symfony, Tether) builds a `ClientRequest`'s body and parses its form the same
  way: a PSR-7 stream over the connection, and the fields/files PHP would put in `$_POST`/`$_FILES`,
  under the same `php.ini` limits. swerve-psr15 now uses these instead of its own copies.
- Adapter discovery: an installed package that declares `"extra": {"swerve": {"adapter": "name", "entry": "Function\\name"}}`
  provides the entry point in place of `swerve.php`: each worker calls the function with the application
  directory, and it returns a `Swerve\RequestHandler`. The master reads `vendor/composer/installed.json`
  and loads no adapter code. Chosen by `--adapter=<name>`, the application's composer.json, or being
  the only one installed; `swerve` is the built-in adapter (`swerve.php`). See the README, Adapters.

- `Swerve\WebSocket`: RFC 6455 over the `101` connection of a `ClientRequest`, in core. `from()` runs a
  callback, `accept()` leaves the reading to the handler; messages are pulled (`receive()`, `foreach`) or
  pushed (`$onMessage`), with `$onClose`, subprotocols, an origin allow-list, a `maxMessage` limit,
  server pings, backpressure both ways, and every close code the protocol defines. A drain closes
  sockets with 1001. Its contract is `docs/websocket.md`, pinned by tests that run with and without phasync-ext.
- `WebSocket::handshake()`, `WebSocket::upgrade()` and `WebSocket::run()`, for framework adapters: the
  handshake decision from the parts of any request (method, version, headers, body or not, subprotocols,
  origins), returned as a `Swerve\WebSocketHandshake` (a refusal's status, headers and body, or the `101`
  headers and the chosen subprotocol); the upgrade that sends given `101` headers on a `ClientRequest` and
  runs the callback; and `run()`, the same callback and close codes on a connection (any `phasync\Net\Duplex`)
  whose `101` the adapter already sent itself. `from()` and `accept()` are built on them, with the same
  answers as before. `WebSocket` is `final`.
- `Swerve\ServerSentEvents`: the event stream head, `send()` with `event`, `id` and `retry`, `comment()`
  and `lastEventId()`.

### Changed

- Breaking: `swerve.php` returns a `Swerve\RequestHandler` wrapping a closure that takes a
  `Swerve\ClientRequest`, in place of a PSR-15 request handler. The closure runs once for each HTTP
  exchange, in the connection's own coroutine and a phasync context of its own, and may keep the
  request (an event stream, a long download) by not returning. A `ClientRequest` is a
  `phasync\Net\Duplex`: `read()` is the request body, `write()` the response body, with
  `sendResponseHeaders()` (`1xx` interim responses, `101` to the raw connection), `flush()`,
  `sendFile()` and `end()` with trailers; a second final head throws `HeadersSentException`.
- HTTP/1.1 is served through phasync/net. The module owns the framing, adds `date` to every
  response, including its own error responses (#35), and `server: Swerve` unless the handler sets one.
- `Swerve\StaticFiles` (`--public`) is no longer middleware: `wrap(Closure $app)` returns a handler that
  answers file requests and calls `$app` for the rest.
- `--ext` stops swerve with an error when the extension cannot be loaded; the start notice about a
  missing extension is logged only when `composer.json` enables it, or does not mention it.

### Removed

- FastCGI mode and `--fastcgi`; `--buffer-responses`.
- `Swerve\Dispatcher` and the PSR-7 request, response and body classes, `StreamingResponderInterface`
  and `UnbufferedStream` response bodies. There is no PSR-7 request: a framework is served through an
  adapter that turns a `ClientRequest` into its own request and response.
- `Swerve\Http\WebSocket` and `ProtocolUpgrade`, replaced by `Swerve\WebSocket` on the `101` connection.
- `Swerve::virtualize()`.
- The PSR-15 and Slim requirements.

### Fixed

- A drain (`Swerve\Http\HttpConnection::drain()`) and a WebSocket ending itself (`WebSocket::end()`)
  could race to cancel the same pending read: whichever lost found the fiber already had a
  cancellation on its way and crashed the worker with an uncaught `LogicException` ("the coroutine is
  not waiting"), instead of the drain or the end() having nothing left to do. Both now let that
  `LogicException` go, since `phasync::throw()` delivers only once. (phasync/swerve#36)
- A connection closed before its first request no longer logs "Undefined variable $keepAlive".
  (phasync/swerve#38)

## 0.1.0-beta5 (2026-10-04)

### Added

- `--ext` loads phasync-ext, which now ships inside phasync (2.0.0-beta7, now required), without
  touching `composer.json`. Swerve also loads it when the project's `composer.json` has
  `"extra": {"phasync": {"ext": true}}`; otherwise it no longer loads it, and the start notice says
  how to enable it.

### Changed

- phasync-ext is no longer a separate package: `composer require phasync/phasync-ext` is replaced by the
  setting or flag above.

## 0.1.0-beta4 (2026-10-04)

### Changed

- `Swerve::virtualize()` needs phasync-ext 0.5.0-beta4 or later: 0.5.0-beta3 did not build on PHP 8.2 and 8.3.

## 0.1.0-beta3 (2026-10-04)

### Added

- `--trusted-proxy=<ip|cidr|unix>` (HTTP mode, repeatable): for requests from a trusted peer,
  `REMOTE_ADDR`, `HTTPS`, `SERVER_PORT` and the host come from `X-Forwarded-For`, `-Proto` and `-Host`.
- With `Swerve::virtualize()`: `getallheaders()`, `apache_request_headers()` and
  `fastcgi_finish_request()`; `$_SERVER` has `SERVER_NAME`, `REQUEST_SCHEME`, `PHP_AUTH_USER`,
  `PHP_AUTH_PW`, `PHP_AUTH_DIGEST`, `AUTH_TYPE`, `SERVER_SOFTWARE`, `DOCUMENT_ROOT`, `SCRIPT_FILENAME`,
  `SCRIPT_NAME`, `PHP_SELF` and, over HTTP, `SERVER_ADDR` and `SERVER_PORT` (#28).
- With phasync-ext 0.5.0-beta3 (now required by `Swerve::virtualize()`) and phasync 2.0.0-beta6 (now required): `ini_set()`, `set_time_limit()`, `error_get_last()`, the time zone, `mt_srand()` and `mysqli_report()` are per request.
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
