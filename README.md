# Swerve

![SWERVE](swerve-logo.png)

> EARLY DEMO RELEASE, BUGS TO BE EXPECTED

## Getting started

1. Create a file named `swerve.php` in your application root. This file must return
   a PSR-15 RequestHandlerInterface. For example:

```php
<?php

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->get('/', function (RequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write('Hello, World');

    return $response;
});

return $app;
```

2. Install `swerve`: `composer require phasync/swerve`

3. Run `./vendor/bin/swerve` to launch the web server.

## Usage

```bash
> ./vendor/bin/swerve --help
Usage: swerve [-mhvq] [-w,--workers=<processes>] [--fastcgi=<ip:port>] [--http=<ip:port>] [--max-body=<bytes>] [--grace=<seconds>] [--watchdog=<seconds>] [--max-memory=<size|P%>] [--max-requests=<n>] [--log=<path>] [swerve.php]

-m,--monitor              Watch the application's PHP files and do a rolling reload on change
-w,--workers=<processes>  Number of worker processes (default: auto)
--fastcgi=<ip:port>       IP and port for FastCGI server; [::1]:9000 for IPv6
--http=<ip:port>          IP and port to serve HTTP on; [::1]:8080 for IPv6 (default: 127.0.0.1:8080)
--buffer-responses        HTTP mode: read each response body whole (up to 8 MiB) and send it in one write with a Content-Length
--max-body=<bytes>        HTTP mode: the largest request body in bytes (413), 0 for no limit (default: 8388608)
--grace=<seconds>         Seconds workers get to finish requests on shutdown, reload and recycle before SIGKILL (default: 30)
--watchdog=<seconds>      A worker whose event loop is silent this long is killed and replaced (a request doing more CPU work than this without yielding counts as stuck); at least 1, or 0 = off (default: 30)
--max-memory=<size|P%>    Recycle a worker above this memory (after gc): bytes, K, M or G, or a % of memory_limit (off when memory_limit is -1); 0 = off (default: 80%)
--max-requests=<n>        Recycle a worker after about n requests; 0 = off
--log=<path>              Append all log lines, and PHP errors, to this file instead of the terminal
-h,--help                 Display this help message
-v,--verbose              Log more: -v also info, -vv also debug (by default notices and up)
-q,--quiet                Suppress all terminal output (--log still logs to its file)
[swerve.php]              Full path to application php file
```

swerve runs in the foreground, as systemd, Docker and supervisord expect; there is no
daemon mode. Elsewhere, `nohup swerve -q --log=/var/log/swerve.log &` does the same.

## Modes

**HTTP (the default).** swerve starts one worker process per CPU core, and every worker
serves HTTP/1.1 itself on the `--http` address. The kernel spreads new connections over
the workers. There is nothing else to install or run.

**FastCGI (`--fastcgi`).** For running swerve behind a web server of your own, such as
nginx or HAProxy, which speaks FastCGI to the workers. swerve supports several requests
multiplexed over one FastCGI connection; `etc/haproxy.cnf` is an example HAProxy
configuration that uses it.

## Supervision

A master process starts the workers and looks after them. It never loads the application
itself: each worker loads it after starting, so a reload runs the current code.

| Signal to the master | Effect |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C), `SIGQUIT` | Graceful shutdown: the workers stop accepting, finish the requests in flight (answered with `Connection: close`), close idle keep-alive connections and exit. What is left after `--grace` seconds is killed. A second signal kills at once. |
| `SIGHUP`, `SIGUSR2` | Rolling reload: the workers are replaced one at a time. Each new worker listens before the old one drains, so there is always a listener. The `--log` file is reopened first, by the master and the workers, so `logrotate` can rename it and send `SIGHUP`. |
| `SIGUSR1` | Reopen the `--log` file only. |

`SIGQUIT`, `SIGUSR1` and `SIGUSR2` mean what they mean to php-fpm, so its deploy and
`logrotate` scripts work unchanged. The master asks a worker to drain over its pipe, not with a
signal, which would cut short a blocking call (`sleep()`, `stream_select()`) of a request in
flight.

Workers ignore `SIGINT` and `SIGHUP` sent to the whole process group (Ctrl+C, a closing
terminal): only the master decides. When the master itself dies, the workers drain and exit;
one stuck in a busy loop or a blocking call, which can't drain, kills itself a second after the
`--watchdog` timeout, so that it doesn't keep the port (with `--watchdog=0` it stays until it
gets unstuck); one still loading the application does the same a second after the 60 seconds
the master allows for that. A worker sent `SIGTERM` by someone else (an operator, the OOM
tooling) drains, and the master starts another in its slot. `SIGQUIT` to a worker logs what it
is doing: its requests in flight, and where its code runs.

- **Crashes.** Every worker exit is logged with its exit code or signal, and the worker is
  restarted. A worker that dies before it was ready, or within 5 seconds of it without having
  served a request, is a failed start: its slot waits 0.5, 1, 2, 4, ... up to 30 seconds before
  the next start, and three in a row are logged as a crash loop. A worker that served requests
  started fine: a request killed it, and it is restarted at once. When every slot failed to
  start before any worker became ready (the application can't load, the port is taken), swerve
  exits with the worker's exit code, or 1 when that is 0 (`die('no config')`). A new worker of a
  reload or a recycle that fails to start leaves the old one serving, and is retried in its
  slot with the same backoff, so a failure that passes (a database away for a moment) doesn't
  leave the old code serving for good. A PHP fatal error is logged by its worker, with the
  requests it had in flight. An exception in
  the application answers 500, is logged with its request, and the worker serves on; one in a
  background coroutine nobody awaits is logged too. The master also reaps children it did not
  start, as PID 1 in a container without `--init` or after `job & exec swerve` in an
  entrypoint.
- **Stuck workers.** Each worker's event loop sends the master a heartbeat four times a second.
  Requests starting and ending count too, so a worker busy with many short blocking requests in
  a row is not taken for stuck. A worker silent for `--watchdog` seconds, stuck in a busy loop or
  a blocking call, logs its requests in flight and where its code runs (`SIGQUIT`), and is
  killed and replaced. So is a request that does more CPU work than that without yielding; raise
  `--watchdog`, or set it to 0, for such applications. A worker uses `SIGALRM` to notice that
  the master died while it was stuck (see above), a second after the timeout, so that a live
  master kills it first and a shorter blocking call (`sleep()`, `stream_select()`) is never
  cut short; with `--watchdog=0` the application may use `SIGALRM` itself.
- **Memory leaks.** After each request, and every tick, a worker compares
  `memory_get_usage(true)` with `--max-memory` (by default 80 % of `memory_limit`, and off when
  `memory_limit` is -1 unless the option is given). Above it, after a garbage collection, the
  master starts a replacement and the leaking worker drains. `--max-requests` recycles after
  about so many requests. The limits get some jitter, so workers don't recycle together. A
  `--max-memory` not below `memory_limit` could never be reached first: it is logged as a
  warning, and recycling is off. A
  worker that hits `memory_limit` itself dies with PHP's fatal error (in the log with `--log`),
  loses its requests in flight, and is replaced.
- **`--monitor`** watches the `*.php` files next to and below the swerve file (not in
  `vendor/`, nor names starting with a dot, such as editors' lock files; but
  `vendor/composer/installed.php`, so `composer install` counts) and reloads 1 to 2 seconds
  after the last change. Directories it can't read are skipped. The scan runs in the master:
  on a large tree (`node_modules`, caches) it waits ten times as long as the last scan took
  before the next, so that the master stays mostly idle, and changes take that much longer to
  be noticed.

What a reload picks up: the swerve file and every class autoloaded in the worker, with
Composer's class map and PSR-4 prefixes read again (`opcache_reset()` is called too). It does
not pick up swerve itself, phasync, Composer `files` autoloads, `php.ini` or the command line
options; restart for those. The swerve file's path is not resolved, so a deploy that points a
`current` symlink at a new release and sends `SIGHUP` runs the new release; but the class map
and PSR-4 prefixes read again are those of the `vendor/` directory the master loaded at its
start, so an application whose swerve file requires its own release's `vendor/autoload.php`
is safest.

Operating notes:

- Linux resets connections still waiting in the accept queue of a listener that closes. For
  handovers without any lost connection, set `sysctl net.ipv4.tcp_migrate_req=1` (swerve logs a
  hint at startup when it is 0). In FastCGI mode, nginx retries such requests with
  `fastcgi_next_upstream error`.
- Give the process manager more time than `--grace`: `docker stop -t 35`, Compose
  `stop_grace_period: 35s` or systemd `TimeoutStopSec=35` for the default 30 seconds. During a
  handover a slot briefly has two workers, so budget for one worker's memory more.
- Long responses such as Server-Sent Events or long polling are cut at the end of the grace
  period.
- Without `-v` the log has notices and up: reloads, recycles, every worker exit, the pid of
  each worker started after the first ones, and shutdown. `-v` adds the first starts and each
  worker's readiness, `-vv` debug lines. What a client sent, such as a request target in an
  error, is logged as it is, with control characters escaped.
- swerve refuses to start on an address something already listens on. Its own workers share
  the address with `SO_REUSEPORT`, which would otherwise let a second swerve (a forgotten one,
  or one started by hand next to systemd's) silently take a share of the connections.
- Log lines to a pipe that nobody reads (a stalled log collector) are dropped rather than
  block the server; with `--log` they go to a file, which never blocks. A line that can't be
  written (a full disk, a log reader gone) is dropped without a PHP warning, which an
  application's error handler could turn into an exception.
- A worker at its limit of connections (its open-file limit less 64, at most 960) logs a
  warning, at most once a minute.
- Options go before the swerve file: anything after it is refused.
- The listeners and the workers' pipes to the master are close-on-exec (this needs the
  `sockets` extension of PHP 8.4 or later), so a background job the application starts
  (`exec('job &')`, `proc_open()`, `mail()`) doesn't keep the port after its worker died or
  swerve stopped. A client connection is still inherited, and the job holds it open until it
  exits.
