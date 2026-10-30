# Troubleshooting and reporting bugs

## Common problems

**Every request of a worker is slow, or stalls in bursts.** Something blocks the worker: a
PostgreSQL query, phpredis, heavy computation. See
[the rule](how-it-runs.md#the-rule-never-block-a-worker).

**Other requests are cut off at once.** A request called `exit()` or `die()`, which ends the run of
`swerve.php` and every request it serves at that moment. In a `swerve.php` that loops, return from
the handler instead.

**`Swerve::accept() is called by the worker's swerve.php, not inside a request`.** `accept()` belongs
in `swerve.php` itself, not in code a request runs.

**`Swerve::cache() needs APCu`.** Install it (`pecl install apcu`); swerve turns it on for the
command line itself. Publish/subscribe needs it too.

**`Can't create a coroutine outside of a coroutine`.** `phasync::go()` was called outside an event
loop, such as in a script run on its own. Inside swerve (`swerve.php`, its requests) coroutines
work.

**Changes to the code don't show.** Workers don't check files for changes (swerve turns off
`opcache.validate_timestamps`). Use `--watch` during development, or reload
(`kill -HUP <master pid>`, `systemctl reload`).

**Data is different from one request to the next.** Each worker has its own memory, and requests
go to different workers. Keep shared state in `Swerve::cache()`, a database or Redis, and use
[publish and subscribe](publish-subscribe.md) to notify the other workers.

**`Swerve::upgrade(): the WebSocket moves to the WebSocket worker, and what goes with it can't be
serialized`.** With a bootstrap file, the handler and the request's state are copied to a
WebSocket worker: a closure captures plain data, not connections or services, and `$_SESSION`
holds what `serialize()` takes. See
[WebSockets](realtime.md#inline-or-moved-to-websocket-workers).

**A worker stops answering while WebSockets are open.** In a `swerve.php` that accepts one request
per run (WordPress's), `Swerve::upgrade()` keeps the worker for as long as its WebSocket is open.
Pass a bootstrap file to move WebSockets to WebSocket workers.

**`Swerve\HeadersSentException`.** The final head was committed already: by an earlier
`sendResponseHeaders()`, or by a `write()`, `flush()`, `end()` or `sendFile()` that sent the implicit
`200`. Send the head first.

**An SSE stream arrives all at once, at the end.** A proxy buffers it (nginx:
`proxy_buffering off`).

**`At the limit of … connections`.** A process reached its open-file limit; see
[Sizing](production.md#sizing).

**A reload takes the full grace period.** Something catches the `Swerve\WorkerStoppingException`
and waits on, or waits inside `phasync::shielded()`: the log says how many processes still ran.

## Reporting a bug

A bug report that can be reproduced gets fixed; please include:

1. What happened, and what you expected, with the exact error or log lines.
2. The versions: `composer show 'phasync/*'` and `php -v`.
3. The smallest `swerve.php` that shows it, the command line you ran it with, and the request
   (a `curl` command, or a few lines of client code).

Where:

- Swerve (the server, the command line, publish/subscribe, static files):
  <https://github.com/phasync/swerve/issues>
- phasync (coroutines, channels, timeouts):
  <https://github.com/phasync/phasync/issues>
- ext-phasync (the extension): <https://github.com/phasync/ext-phasync/issues>

When unsure which, file it with swerve.
