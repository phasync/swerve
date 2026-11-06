# A server in your own program

`swerve` starts your application: a master, workers, `swerve.php`. A program that is something
else first, such as a CLI tool with a terminal UI, an agent, or a test, can serve HTTP itself
with `Swerve\Server`, in its own process, next to what it already does:

```php
use Swerve\Server;

phasync::run(function () use ($app, $tui) {
    $web = new Server('127.0.0.1:8080', $app->handle(...), __DIR__ . '/public');
    $tui->run();     // the program's own work; requests are served meanwhile
    $web->close();
});
```

The server listens from the constructor on, and serves every request in a coroutine of this
process, concurrently, until `close()`. Requests see the program's objects as they are: what the
terminal UI shows and what the web page shows can be the same state.

`$handler` takes what [`Swerve::accept()`](requests-and-responses.md) takes: a closure taking a
PSR-7 `ServerRequestInterface` (`$app->handle(...)` of a PSR-15 handler), one taking the
`Swerve\ClientRequest`, one without parameters (`echo` and `header()`, under `sapi()`), a
script's path, or `null` for the static files alone. A request has its own `Scope`, as in a
worker, and `Swerve::upgrade()`, WebSockets, Server-Sent Events, `Swerve::finishRequest()`,
`Swerve::publish()` and `Swerve::subscribe()` work as they do there. `$server->address` is where
it listens: ask for `127.0.0.1:0` and read the port there. `unix:/path` listens on a socket file.

`close()` stops accepting and closes the connections that wait for a request. The requests of
this server see `Swerve::draining()` true, their `Swerve::onShutdown()` callbacks run and their
`Swerve::awaitShutdown()` returns, so a WebSocket can say goodbye. They have 5 seconds to end;
then their coroutines get a `WorkerStoppingException`. `close()` returns once every connection
ended. A program may run several servers, and start a new one after closing another.

What one process does not have:

- No workers: the requests share one CPU core with the rest of the program, and code that
  blocks (a synchronous HTTP client, `sleep()` without ext-phasync's overrides) holds up all of
  them. It suits code that waits asynchronously: phasync, or any library on fiber-interop/api.
- No isolation: a fatal error or exhausted memory in a request ends the program.
- No reload, `--watch` or recycling, and no WebSocket workers: `Swerve::upgrade()` with a
  bootstrap file needs `swerve`; `Swerve::accept()` is for `swerve.php` only.
- `Swerve::cache()` needs APCu enabled for the CLI (`apc.enable_cli=1`), which `swerve` sets for
  its workers.

Logging: failed requests go to the logger given as the fourth argument, else to `Swerve::log()`,
which writes to STDERR. PHP's own warnings go where `error_log` points, which in the CLI is
STDERR too. A terminal UI gives its own logger and sets `error_log` to a file, or both write
over its screen.

ext-phasync is needed, as for `swerve`.
