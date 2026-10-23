# Swerve documentation

Swerve is a PHP application server: it runs your application in long-lived worker
processes, serves HTTP/1.1 itself, and lets one request stay open for as long as it likes
(Server-Sent Events, long polling) while the worker goes on serving others.

Read in this order when you build an application on swerve:

1. [Getting started](getting-started.md): install, a first application, running it.
2. [How swerve runs your application](how-it-runs.md): workers, coroutines, what is shared
   and what is not, and the one rule that matters most: never block a worker.
3. [Swerve's three modes](modes.md): Classic, Persistent and Async in full: `swerve.php`,
   `withAsync()`, handlers and adapters, a Classic script's own services, and WebSockets and
   Server-Sent Events per mode.
4. [swerve.php as bootstrap](bootstrap.md): what the application file may start besides the
   handler (coroutines, subscribers, servers of your own), and when the cache and messages work.
5. [Requests and responses](requests-and-responses.md): the `ClientRequest` reference: the request,
   the response head and body, static files, logging.
6. [Realtime: Server-Sent Events, WebSockets and raw connections](realtime.md): long-lived responses, 101
   upgrades, and a complete chat example.
   [WebSockets and Server-Sent Events](websocket.md) is the contract of `Swerve\WebSocket` and
   `Swerve\ServerSentEvents`: handshake, close codes, ordering, backpressure, limits.
7. [Publish and subscribe](publish-subscribe.md): messages between the workers; and the
   [shared cache](../README.md#shared-cache), `Swerve::cache()` and
   [one worker at a time with `flock()`](../README.md#one-worker-at-a-time).
8. [Command line](command-line.md): every option.
9. [Production](production.md): sizing, ext-phasync, systemd, Docker, nginx and TLS,
   reloads, limits.
10. [Troubleshooting and reporting bugs](troubleshooting.md).

The examples in [`examples/`](../examples) run as they are, and swerve's test suite runs
them: [`sse-chat`](../examples/sse-chat) and
[`memcached`](../examples/memcached), a memcached server in every worker on top of the shared
cache, with a [step-by-step tutorial](../examples/memcached/README.md).

Swerve is at 0.x: options and APIs may change before 1.0. It runs on Linux, with PHP 8.2 or
later and the `pcntl`, `posix` and `sockets` extensions.
