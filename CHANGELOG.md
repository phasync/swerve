# Changelog

## Unreleased

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
- `Virtual::run($request, $code, handOver: true)`: the code gives the response to a `$respond`
  closure, whenever it has one, instead of the first output committing it (#23).

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
