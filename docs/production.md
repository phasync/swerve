# Production

## ext-phasync

ext-phasync is the PHP extension Swerve runs on. It ships inside
[phasync](https://github.com/phasync/phasync), with prebuilt binaries for PHP 8.3 to 8.5 on Linux
and macOS: `vendor/bin/swerve` loads the one for your PHP by itself, and uses one installed with
`pie install phasync/ext-phasync` as it is. When neither fits (an extension phasync can't use),
swerve says why and exits with code 1.

What it does:

- A worker waits on its sockets with epoll, and is not limited by `stream_select()`, which fails for
  file descriptors of 1024 and up.
- Code that is not written for phasync cooperates: inside a coroutine, sockets and TLS, MySQL through
  mysqli or PDO, `curl_exec()` and Guzzle, pipes, file and DNS functions, `sleep()` and `usleep()` let other
  requests run instead of blocking the worker. CPU-bound code still blocks. See
  [the rule](how-it-runs.md#the-rule-never-block-a-worker).


## PHP settings swerve overrides

swerve is a long-running process, like Node.js. `php.ini` still loads the extensions and sets
everything else, but swerve overrides the settings made for shared hosting and its opcache
configuration, with one restart at startup. An explicit `-d` on the command line still wins.

| Setting | Value | Why |
|---|---|---|
| `memory_limit` | a quarter of the memory (the container's, if lower), at most 4 GiB | per worker, as V8 sizes a Node.js heap: a worker that reaches it dies and another starts |
| `max_execution_time`, `max_input_time` | 0, -1 | no per-request time limits in a long-running process |
| `open_basedir`, `disable_functions`, `disable_classes` | empty | shared-hosting fences; swerve itself needs `pcntl_*` and `posix_*` |
| `auto_prepend_file`, `auto_append_file`, `opcache.preload` | empty | no code injected into the server from outside the application |
| `realpath_cache_size`, `realpath_cache_ttl` | 64M, 86400 | files only change through a reload, which starts new workers |
| `opcache.enable_cli` | 1 | the opcode cache is off by default on the command line |
| `opcache.jit`, `opcache.jit_buffer_size` | 1054, 64M | the JIT is off by default since PHP 8.4. 1054 is the tracing JIT without register allocation: with it, PHP 8.5's tracing JIT can leave a variable undefined after an exception thrown by an internal call, such as `Fiber::resume()`, is caught in the same function |
| `opcache.memory_consumption`, `opcache.interned_strings_buffer`, `opcache.max_accelerated_files` | 4096, 64, 100000 | room for any application: the cache is reserved address space, and only takes RAM as compiled code fills it |
| `opcache.max_file_size` | 0 | every file is cached, whatever its size |
| `opcache.validate_timestamps`, `opcache.file_update_protection` | 0, 0 | changed code arrives through a reload, which makes opcache forget every script |
| `opcache.enable_file_override` | 1 | `file_exists()` and `is_file()` on PHP scripts answer from the cache |
| `opcache.save_comments` | 1 | attributes and annotations need doc comments |
| `opcache.file_cache`, `opcache.file_cache_consistency_checks`, `opcache.consistency_checks`, `opcache.protect_memory` | off | shared memory only; no debugging checks |

## Sizing

**Workers.** `--workers=auto` (the default) is one per CPU (hardware thread). On large machines
fewer can be faster: on a 2-socket server with 56 hardware threads, a hello-world app served the
most requests with 16 workers. Measure with your own application. Each worker holds its own copy
of your application in memory.

**Several sockets.** On a machine with more than one NUMA node, worker *i* pins itself to node
*i* mod the number of nodes, so it stays near the memory it allocated: about a quarter more
throughput at 10,000 connections on a 2-socket server. It uses FFI when PHP allows it (the CLI
does by default), else `taskset`; without either, workers stay unpinned. Only CPUs the process
may already use count: under `taskset`, a cgroup cpuset or `--cpuset-cpus`, a worker pins to its
node's share of them, and when they all lie on one node, nothing is pinned. With `-v` the log
shows each worker's CPUs.

**Connections per worker.**

- *Without ext-phasync*, a worker holds at most **512 connections**: half of PHP's `stream_select()`
  limit of 1024 descriptors, because your application opens files and connections of its own,
  about one per client on average. Long-lived
  connections (SSE, long polling, upgraded ones) use one each for as long as they are open. For
  many of them, run more workers than cores: **`--workers` of four times the cores** is a good
  start (4 cores: 16 workers, about 8,000 connections). Idle connections cost almost no CPU,
  so the extra workers don't compete much; they cost memory, one application each.
- *With ext-phasync*, the limit is the open-file limit less 64 (`ulimit -n`; `LimitNOFILE` under
  systemd). Past a few hundred **busy** connections per worker, waiting on them gets
  expensive; more workers help here too.

At the limit, a worker closes connections that sit idle (kept-alive ones, ones that never sent
a request) to make room for new ones, and logs a warning at most once a minute. Upgraded
(101) connections and ones whose request is still being handled are never closed to
make room.

**Memory.** A worker that reaches its `memory_limit` dies with PHP's fatal error and is
replaced, losing its requests in flight; there is no recycling before that, as in Node.js.

`memory_limit` bounds the sum of the requests running at once in a worker, and a fatal error (out of
memory, say) ends the worker and every request in it, not only the request that caused it. Count
`memory_limit` as the peak of one request times the concurrency you expect.


## Behind a proxy

Behind nginx or HAProxy, `REMOTE_ADDR` is the proxy, as on php-fpm. The application reads the
`X-Forwarded-For`, `-Proto` and `-Host` headers of the proxies it trusts itself (Laravel's and
Symfony's trusted proxies, for example).

## systemd

```ini
# /etc/systemd/system/myapp.service
[Unit]
Description=myapp
After=network.target

[Service]
User=myapp
WorkingDirectory=/srv/myapp/current
ExecStart=/usr/bin/php vendor/bin/swerve --http=127.0.0.1:8080
ExecReload=/bin/kill -HUP $MAINPID
Restart=always
LimitNOFILE=1048576
# Longer than --grace (10 s), so swerve's own drain finishes first
TimeoutStopSec=20

[Install]
WantedBy=multi-user.target
```

`systemctl reload myapp` replaces each worker with one running the current code: deploy, then
reload. The old worker stops once its replacement is ready, after finishing its requests; no
connection is refused, and the cache is kept. A change to `php.ini` or to swerve itself needs a
restart. Logs go to the journal (`journalctl -u myapp`).

## Docker

```dockerfile
FROM php:8.4-cli
RUN docker-php-ext-install pcntl sockets
WORKDIR /app
COPY . .
CMD ["vendor/bin/swerve", "--http=:8080", "--public=public"]
```

`docker stop` sends `SIGTERM` and waits 10 s by default, as long as swerve's `--grace`: give it
`--time=20` (or `stop_grace_period: 20s` in compose), so swerve's own drain finishes first. The
container's CPU count is what the default `--workers` sees.

## nginx in front: TLS, and SSE through it

Swerve serves plain HTTP. For HTTPS, put a reverse proxy in front. For nginx:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl;
    http2 on;
    server_name chat.example.com;
    ssl_certificate     /etc/letsencrypt/live/chat.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/chat.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        # Upgrades (101)
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        # Server-Sent Events: send each event on at once, and keep long-lived requests open
        proxy_buffering off;
        proxy_read_timeout 1h;
    }
}
```

Caddy does all of that with `reverse_proxy 127.0.0.1:8080`. The request's `peer()` is then the
proxy's, unless it is a [trusted proxy](#behind-a-proxy); the client's address is in
`X-Forwarded-For`.

## Reloads, shutdown, and long-lived connections

- On a reload or shutdown, each worker stops accepting and finishes its requests in flight; then
  every coroutine still running (upgraded connections, event streams, work in the background)
  gets a `Swerve\WorkerStoppingException` at its wait, and a second to end: a `Swerve\WebSocket`
  closes with 1001 (1012 on a reload). What still runs after `--grace` (10 s) is killed. Clients
  must reconnect, as after any server's death: `EventSource` does by itself; write reconnecting
  into other clients.

## Logs

Swerve logs to standard output, which systemd and Docker collect. `--log=<file>` appends to a
file instead; after rotating it, send `SIGUSR1` (logrotate's `postrotate`), or use
`copytruncate`. PHP's own errors go to the same place. `-q` logs nothing to the terminal.

Next: [Troubleshooting](troubleshooting.md).
