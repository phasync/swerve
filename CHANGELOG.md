# Changelog

## Unreleased

### Changed

- Without phasync-ext, output outside a response (`echo`, `var_dump()`, ...) ends the worker with a
  message on standard error that names the output, the request, and the file and line, and the
  master starts a new worker. Before, it went to the terminal unnoticed. With phasync-ext nothing
  changes. See `docs/stray-output.md`.
