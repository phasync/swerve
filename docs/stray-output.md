# Stray output

Output that is not part of a response (`echo`, `print`, `var_dump()`, `printf()`, template
code that prints, a closing `?>` tag) has nowhere to go in a worker. Without
[phasync-ext](production.md#phasync-ext), swerve ends the worker on the first byte of it,
rather than let it be lost or sent to the wrong client.

## Without phasync-ext

Each worker puts an output buffer under everything else, before your `swerve.php` loads. The
first byte that reaches it makes the worker write this to standard error (not to `--log`) and
exit with status 4:

```
Stray output is not compatible with swerve.
  Output:  "debug: 42\n" (10 bytes)
  Request: GET /orders
  At:      /app/src/OrderController.php:57
  Remedy:  serve this application with php-fpm or similar, or install phasync-ext.
```

- The output is escaped and cut at 200 bytes. `Request` is the request of the coroutine
  that wrote it, also when two requests overlap; `none` when it happened while `swerve.php`
  loaded or in a background coroutine. `At` is the file and line of the `echo`.
- The master starts a new worker. The request that printed gets no response; other requests
  in that worker are cut off, as with `exit()`.
- If `swerve.php` itself prints while it loads, the worker never becomes ready and swerve
  stops with `failed to start`.
- The buffer cannot be removed: `ob_end_clean()` and `ob_end_flush()` fail on it. Your own
  `ob_start()` on top works: what it captures never reaches the guard, and only what it
  lets through does. A loop `while (ob_get_level()) ob_end_clean();` never ends (the
  [watchdog](command-line.md) stops the worker); stop at the level you started from, as
  Symfony's `Response::closeOutputBuffers()` does.
- Responses written through PSR-7 streams, including Server-Sent Events and WebSockets
  through swerve's own classes, do not pass through output buffering.

## With phasync-ext

No guard is installed. A route that prints runs as a request of its own with
[`Swerve\Http\Virtual`](../src/Http/Virtual.php): what it prints, and the headers it sets,
become the response.
