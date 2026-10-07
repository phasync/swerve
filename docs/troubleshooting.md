# Troubleshooting and reporting bugs

## Common problems

**Every request of a worker is slow, or stalls in bursts.** Something blocks the worker: a
slow database query, `curl`, `sleep()`, heavy computation. See
[the rule](how-it-runs.md#the-rule-never-block-a-worker). With `--watchdog` (on by default), a
worker stuck for 30 s is replaced, and the log says which request it was stuck in, and where.

**`Can't create a coroutine outside of a context`.** `phasync::go()` was called outside
swerve's event loop, such as in a script run on its own. Inside swerve (your application file,
its handlers) coroutines work.

**Changes to the code don't show.** Workers load the application once and don't check files
for changes (swerve turns off `opcache.validate_timestamps`). Use `--watch` during
development, or reload (`kill -HUP <master pid>`, `systemctl reload`).

**Data is different from one request to the next.** Each worker has its own memory, and
requests go to different workers. Keep shared state in a database or Redis, and use
[publish and subscribe](publish-subscribe.md) to notify the other workers.

**`sendResponseHeaders(101)` throws.** A 101 answers an HTTP/1.1 request with `Connection: upgrade`
and an `Upgrade` header, whose body you have read to its end. See
[Raw connections](realtime.md#raw-connections-and-websockets).

**`Swerve\HeadersSentException`.** The final head was committed already: by an earlier
`sendResponseHeaders()`, or by a `write()`, `flush()`, `end()` or `sendFile()` that sent the implicit
`200`. Send the head first.

**An SSE stream arrives all at once, at the end.** A proxy buffers it (nginx:
`proxy_buffering off`).

**swerve stops with exit code 2.** The log says why: `swerve.php` (or an adapter's entry function) threw
while loading, or returned something other than a `Swerve\RequestHandler` (which takes a closure). At
start, a message on stderr says that several adapters are installed and none is chosen, or that the
adapter named is not installed: see [Adapters](../README.md#adapters).

**`At the limit of 512 connections`.** A worker is full; see [Sizing](production.md#sizing).

**A reload takes the full grace period.** Something catches the `Swerve\WorkerStoppingException`
and waits on, or waits inside `phasync::shielded()`: the log says how many coroutines were still
running. A recycled worker, on the other hand, keeps upgraded connections for up to `--linger`;
see [Production](production.md#sizing).

## Seeing more

- `-v` logs what swerve does (workers starting, ready, draining), `-vv` also debug.
- `--version` prints the versions of swerve, PHP, phasync and phasync-ext.
- `kill -QUIT <worker pid>` makes a worker log the requests it is serving and where its code
  is (the watchdog does this before killing a stuck worker).

## Reporting a bug

Swerve and phasync are beta, and bug reports are how they get to 1.0. A report that can be
reproduced gets fixed; please include:

1. What happened, and what you expected, with the exact error or log lines (run with `-vv`).
2. `vendor/bin/swerve --version`.
3. The smallest `swerve.php` that shows it, the command line you ran it with, and the request
   (a `curl` command, or a few lines of client code).
4. Whether it happens with and without phasync-ext, if you can try both.

Where:

- Swerve (the server, the command line, publish/subscribe, static files):
  <https://github.com/phasync/swerve/issues>
- phasync (coroutines, channels, timeouts):
  <https://github.com/phasync/phasync/issues>
- phasync-ext (the extension): <https://github.com/phasync/phasync-ext/issues>

When unsure which, file it with swerve.
