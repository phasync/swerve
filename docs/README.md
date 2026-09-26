# Swerve documentation

Swerve is a PHP application server: it runs your PSR-15 application in long-lived worker
processes, serves HTTP/1.1 itself, and lets one request stay open for as long as it likes
(Server-Sent Events, WebSockets, long polling) while the worker goes on serving others.

Read in this order when you build an application on swerve:

1. [Getting started](getting-started.md): install, a first application, running it.
2. [How swerve runs your application](how-it-runs.md): workers, coroutines, what is shared
   and what is not, and the one rule that matters most: never block a worker.
3. [Requests and responses](requests-and-responses.md): what a request carries, streaming
   request and response bodies, static files, logging.
4. [Realtime: Server-Sent Events and WebSockets](realtime.md): long-lived responses, 101
   upgrades, and two complete chat examples.
5. [Publish and subscribe](publish-subscribe.md): messages between the workers.
6. [Command line](command-line.md): every option.
7. [Production](production.md): sizing, phasync-ext, systemd, Docker, nginx and TLS,
   reloads, limits.
8. [Troubleshooting and reporting bugs](troubleshooting.md).

The examples in [`examples/`](../examples) run as they are, and swerve's test suite runs
them: [`sse-chat`](../examples/sse-chat) and [`websocket-chat`](../examples/websocket-chat).

Swerve is alpha: options and APIs may change until 1.0. It runs on Linux, with PHP 8.2 or
later and the `pcntl`, `posix` and `sockets` extensions.
