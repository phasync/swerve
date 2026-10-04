# Production

## phasync-ext

phasync-ext is an optional PHP extension that ships inside [phasync](https://github.com/phasync/phasync),
with prebuilt binaries for PHP 8.2 to 8.5 on Linux. Swerve loads it when your project enables it in
`composer.json`, or when you start swerve with `--ext`:

```json
{
    "extra": {"phasync": {"ext": true}}
}
```

```bash
vendor/bin/swerve --ext          # the same, without touching composer.json; swerve stops if it cannot load
vendor/bin/swerve --version      # ... phasync-ext 0.5.0-beta5
```

Swerve runs without it, which is fine for development; the master logs a notice at start, saying how
to enable it, when it is not loaded.

With it:

- A worker waits on its sockets with epoll, and is not limited to 512 connections
  (PHP's own `stream_select()` fails for file descriptors of 1024 and up, so without it a worker keeps to half that; see sizing below).
  At 50,000 connections a hello-world server served 2 to 4 times as many requests as without it,
  depending on the number of workers. Up to 512 connections per worker, PHP's own
  `stream_select()` is as fast or a little faster.
- Code that is not written for phasync cooperates: inside a coroutine, sockets and TLS, MySQL through
  mysqli or PDO, `curl_exec()` and Guzzle, pipes, file and DNS functions, `sleep()` and `usleep()` let other
  requests run instead of blocking the worker. CPU-bound code still blocks. See
  [the rule](how-it-runs.md#the-rule-never-block-a-worker).

Your application behaves the same with and without it; it only waits better.

## Sizing

**Workers.** `--workers=auto` (the default) is one per CPU (hardware thread). On large machines
fewer can be faster: on a 2-socket server with 56 hardware threads, a hello-world app served the
most requests with 16 workers. Measure with your own application. Each worker holds its own copy
of your application in memory.

**Several sockets.** On a machine with more than one NUMA node, worker *i* pins itself to node
*i* mod the number of nodes, so it stays near the memory it allocated: about a quarter more
throughput at 10,000 connections on a 2-socket server. It uses FFI when PHP allows it (the CLI
does by default), else `taskset`; without either, workers stay unpinned. With `-v` the log shows
each worker's CPUs.

**Connections per worker.**

- *Without phasync-ext*, a worker holds at most **512 connections**: half of PHP's `stream_select()`
  limit of 1024 descriptors, because your application opens files and connections of its own,
  about one per client on average. Long-lived
  connections (SSE, WebSockets, long polling) use one each for as long as they are open. For
  many of them, run more workers than cores: **`--workers` of four times the cores** is a good
  start (4 cores: 16 workers, about 8,000 connections). Idle connections cost almost no CPU,
  so the extra workers don't compete much; they cost memory, one application each.
- *With phasync-ext*, the limit is the open-file limit less 64 (`ulimit -n`; `LimitNOFILE` under
  systemd). Past a few hundred **busy** connections per worker, waiting on them gets
  expensive; more workers help here too.

At the limit, a worker closes connections that sit idle (kept-alive ones, ones that never sent
a request) to make room for new ones, and logs a warning at most once a minute. Upgraded
connections (WebSockets) and ones whose request is still being handled are never closed to
make room.

**Memory.** `--max-memory` (80 % of `memory_limit` by default) recycles a worker whose memory
grows past it: a new worker starts, then the old one finishes its plain requests and stops
accepting. With `memory_limit=-1` this is off; give a size such as `--max-memory=512M`.
`--max-requests` recycles after about so many requests.

With `Swerve::virtualize()`, `memory_limit` bounds the sum of the requests running at once in a
worker, and a fatal error (out of memory, say) ends the worker and every request in it, not only the
request that caused it. Count `memory_limit` as the peak of one request times the concurrency you
expect.

A recycled worker lingers: it keeps its upgraded connections (WebSockets, SSE) instead of
dropping them, until the last client has left or `--linger` seconds (1800) have passed, then
closes what is left (WebSockets get 1001) and exits. Its subscriptions, cache and claims go on
working meanwhile. Budget for them: a slot may have up to three lingering workers besides its
serving one, each with its memory (a recycle that would be the fourth waits until one exits, and
is logged once). `--linger=0` drops the connections of a recycled worker at once, as a reload
does. A reload or shutdown does not linger: it ends the lingering too, within `--grace`.

## Behind a proxy

Behind nginx or HAProxy over HTTP, `REMOTE_ADDR` is the proxy until swerve is told to trust it:
`--trusted-proxy=10.0.0.5` (or a range, or `unix` for a Unix socket) makes the `X-Forwarded-For`,
`-Proto` and `-Host` headers of that peer count: `REMOTE_ADDR`, `HTTPS`, `SERVER_PORT` and the host of
`$request->getUri()` are then the client's. The `X-Forwarded-For` chain is read from the right: the first address that is not a trusted
proxy is the client, so a client's own forged entries are never reached. `X-Forwarded-Proto` and
`-Host` are taken as the proxy sent them: trust only proxies that set them.

## systemd

```ini
# /etc/systemd/system/myapp.service
[Unit]
Description=myapp
After=network.target

[Service]
User=myapp
WorkingDirectory=/srv/myapp/current
ExecStart=/usr/bin/php vendor/bin/swerve --http=127.0.0.1:8080 --public=public
ExecReload=/bin/kill -HUP $MAINPID
Restart=always
LimitNOFILE=1048576
# Longer than --grace (30 s), so swerve's own drain finishes first
TimeoutStopSec=40

[Install]
WantedBy=multi-user.target
```

`systemctl reload myapp` replaces the workers one at a time with ones running the current code:
deploy the new code, then reload; no request is dropped. Logs go to the journal
(`journalctl -u myapp`).

## Docker

```dockerfile
FROM php:8.4-cli
RUN docker-php-ext-install pcntl sockets
WORKDIR /app
COPY . .
CMD ["vendor/bin/swerve", "--http=:8080", "--public=public"]
```

`docker stop` sends `SIGTERM` and waits 10 s by default: give it `--time=40` (or
`stop_grace_period: 40s` in compose), or lower `--grace`. The container's CPU count is what
`--workers=auto` sees.

## nginx in front: TLS, and SSE and WebSockets through it

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
        # WebSockets
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        # Server-Sent Events: send each event on at once, and keep long-lived requests open
        proxy_buffering off;
        proxy_read_timeout 1h;
    }
}
```

Caddy does all of that with `reverse_proxy 127.0.0.1:8080`. The request's `REMOTE_ADDR` is
then the proxy's; the client's address is in `X-Forwarded-For`.

Alternatively, `--fastcgi=127.0.0.1:9000` serves FastCGI to nginx's `fastcgi_pass`, several
requests over one connection. HTTP mode is simpler and supports WebSockets; prefer it.

## Reloads, shutdown, and long-lived connections

- On a reload or shutdown, each worker stops accepting, finishes the requests in flight, and
  exits; the kernel hands new connections to the other workers (reload) or refuses them
  (shutdown). A worker gets `--grace` seconds (30); what is still open a second before that is
  dropped.
- WebSocket connections see their request body end at once, so they can say goodbye (see
  [Realtime](realtime.md)); subscriptions end, so SSE responses fed by them end too. A recycle
  is gentler: see Memory above. Other
  long responses are requests in flight: they run until the deadline, unless they check
  `Swerve::draining()`. Clients must reconnect: `EventSource` does by itself; write
  reconnecting into WebSocket clients.
- `sysctl net.ipv4.tcp_migrate_req=1` makes the kernel hand connections waiting in a closing
  worker's queue to another worker instead of resetting them; swerve logs a hint at start when
  it is 0.

## Logs

Swerve logs to standard output, which systemd and Docker collect. `--log=<file>` appends to a
file instead; after rotating it, send `SIGUSR1` (logrotate's `postrotate`), or use
`copytruncate`. PHP's own errors go to the same place. `-q` logs nothing to the terminal.

Next: [Troubleshooting](troubleshooting.md).
