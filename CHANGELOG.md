# Changelog

## Unreleased

### Added

- `Virtual::run($request, $code, handOver: true)`: the code gives the response to a `$respond`
  closure, whenever it has one, instead of the first output committing it (#23).

### Fixed

- A drain with a WebSocket client that had stopped reading ended the worker with a `FiberError`
  (exit 255): the close frame no longer waits for the client (#24).

### Changed

- Without phasync-ext, output outside a response (`echo`, `var_dump()`, ...) ends the worker with a
  message on standard error that names the output, the request, and the file and line, and the
  master starts a new worker. Before, it went to the terminal unnoticed. With phasync-ext nothing
  changes. See `docs/stray-output.md`.
