# Stray output

Output that is not part of a response (`echo`, `print`, `var_dump()`, `printf()`, template
code that prints, a closing `?>` tag) has nowhere to go while a request is being handled.
Without [phasync-ext](production.md#phasync-ext), swerve ends the worker on the first byte of
it, rather than let it be sent to the wrong client.

## Without phasync-ext

Each worker puts an output buffer under everything else, before your `swerve.php` loads. It
acts only while a request is being handled: output while `swerve.php` loads (a startup
banner, debugging), or from a background coroutine while no request is in flight, goes to the
worker's standard output as it would without it. The first byte that reaches it during a
request makes the worker write this to standard error (not to `--log`) and
exit with status 4:

```
Stray output is not compatible with swerve.
  Output:  "debug: 42\n" (10 bytes)
  Request: GET /orders
  At:      /app/src/OrderController.php:57
  Remedy:  serve this application with php-fpm or similar, or install phasync-ext.
```

- The output is escaped and cut at 200 bytes. `Request` is the request of the coroutine
  that wrote it, also when two requests overlap; `none` when it came from a background
  coroutine while a request was in flight. `At` is the file and line of the `echo`.
- PHP also writes the offending output to standard output as the worker exits; that cannot
  be prevented from PHP code, so the message on standard error is the one to read.
- The master starts a new worker. The request that printed gets no response; other requests
  in that worker are cut off, as with `exit()`.
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
