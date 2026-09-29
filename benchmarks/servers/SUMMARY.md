# PHP application servers: swerve, RoadRunner, FrankenPHP, Swoole, OpenSwoole, ReactPHP

Measured 2026-09-28/29. Every server runs the same PSR-15 application; only the bridge between the
server and PSR-7 differs, and each uses its official or natural one. Server and client share one
machine but not its cores: the server on one chiplet, wrk on the other.

## Environment

- Machine (black): AMD Ryzen 9 9950X3D, 16 cores / 32 threads, 1 NUMA node, two chiplets (CCDs):
  CCD0 = CPUs 0-7,16-23 (with the 96 MB 3D V-Cache L3), CCD1 = CPUs 8-15,24-31. Linux 6.17,
  governor `performance`, THP `madvise`. It is a desktop, idle during the runs.
- Server: pinned to CCD0 by starting it under `taskset -c 0-7,16-23`; every process and thread
  inherits it (Go runtimes included: GOMAXPROCS becomes 16). `srv.sh cpus` checked this for each
  run and it is recorded in `raw/lo/*-start.txt`. swerve pins workers to NUMA nodes only on
  multi-node machines, so it does nothing here.
- Client: `taskset -c 8-15,24-31 wrk -t16 -c<16N> --latency http://127.0.0.1:18500/…` (wrk 4.1.0).
- PHP 8.5.11 (NTS CLI) with identical opcache + tracing JIT everywhere: `opcache.enable(_cli)=1
  opcache.validate_timestamps=0 opcache.jit=tracing opcache.jit_buffer_size=128M` (FrankenPHP
  gets the same through `php_ini`; it runs PHP 8.5.11 ZTS).
- N = PHP workers, N ∈ {2, 8}. 8 is CCD0's physical core count, so N=2 → N=8 shows scaling with cores.
- Method: 3 s warm-up, 10 s measured; 2 rounds, servers interleaved within a round, each server
  started fresh per N. Tables show the median of the 2 rounds; throughput differed by at most 2.1 %
  between rounds. "CPUs" = how many of CCD0's 16 hardware threads were busy (from `/proc/stat`,
  sampled over 8 s inside the run). Every server was stopped by process group (plus descendants:
  RoadRunner puts its workers in groups of their own) and nothing was left on the port or in the
  process table after any run. No run had socket errors or non-2xx responses unless noted.

| Server | Version | Configuration, and what was tuned |
|---|---|---|
| swerve | 0.1.0-alpha19+ (commit f577483), phasync 2.0.0-alpha14 | `--workers=N -q --no-access-log` |
| swerve + phasync-ext | same, phasync-ext 0.5.0-alpha13 | the same, `-d extension=phasync.so` |
| RoadRunner | 2025.1.15, roadrunner-http 4.1.0 | `pool.num_workers: N`, `relay: pipes`, `max_jobs: 0`, `access_logs: false`, `logs.level: error`; **ext-protobuf** (5.36.2) loaded in the workers (RoadRunner sends requests protobuf-encoded; without the extension the pure-PHP decoder does it). `GOGC=400` also tried (row `rr+gogc400`). |
| FrankenPHP | 1.12.7, Caddy 2.11.4, Go 1.26.8 | worker mode, `num N`, `match *` (every request to the worker, no file-system lookups), `file_server off`, `num_threads N+1` (32 at N=16 measured the same), `GODEBUG=cgocheck=0`, `admin off` — as its performance docs advise. **The official Docker image's glibc build** (`dunglas/frankenphp:1.12.7-php8.5`), extracted and started natively with its own loader: the static release binaries deadlock (see below). `GOGC=400` also tried (row `franken+gogc400`). |
| Swoole | 6.2.3 | `Swoole\Http\Server` in **SWOOLE_BASE** (2.33M vs 1.26M req/s for SWOOLE_PROCESS on hello at N=16, and ahead on the page too; `raw-tune/`), `worker_num N`, `hook_flags SWOOLE_HOOK_ALL`, `http_compression false`, `log_level ERROR` |
| OpenSwoole | 26.2.0 | the same (SIMPLE_MODE = base; POOL_MODE measured 1.24M vs 2.1M) |
| ReactPHP | react/http 1.11.1, event-loop 1.6.0, socket 1.17.0 | N single-threaded processes on one port with `SO_REUSEPORT`; event loop **ext-ev** (1.2.3), since the default `stream_select` loop is capped at 1,024 descriptors; `StreamingRequestMiddleware` first (no body buffering and no default concurrency limit) |

Swoole, OpenSwoole, ext-ev and ext-protobuf were built from source for PHP 8.5 into `~/bench/ext`
and loaded only with `-d extension=` on the command line.

## The application

- `apps/common/Handler.php`: one PSR-15 handler for every server, responses from nyholm/psr7's
  `Psr17Factory`. Routes: `/` "Hello", `/json` `json_encode(['hello' => 'world'])`, `/wait` waits
  10 ms (as a database query would), `/page` a 10,201-byte HTML page from `common/page.tpl.php`
  (a loop over 50 rows with `htmlspecialchars` and `number_format`).
- The PSR-7 request per server: swerve and ReactPHP their own; RoadRunner the official
  `PSR7Worker` with nyholm factories; FrankenPHP nyholm/psr7-server's
  `ServerRequestCreator::fromGlobals()` inside `frankenphp_handle_request()`, the response emitted
  with `http_response_code()`/`header()`/`echo`; Swoole and OpenSwoole `apps/common/swoole-bridge.php`,
  our own minimal converter (method, URI with query, headers, cookies, query, parsed body, raw body,
  uploaded files; emits status, headers and `end(body)`). The maintained bridge
  (chubbyphp-swoole-request-handler 1.6.7) writes every body in chunks (`Transfer-Encoding:
  chunked`) and supports only ext-swoole, so it was not used.
- `/wait`: swerve + ext `usleep()` (suspends only the request's coroutine), swerve without ext
  `phasync\sleep()`, Swoole/OpenSwoole `usleep()` with the coroutine hooks, RoadRunner and
  FrankenPHP blocking `usleep()` (one request per worker is their model). ReactPHP's handler cannot
  block: its adapter waits on a 10 ms timer promise and then calls the handler (whose wait is a no-op there).
- On the wire, RoadRunner and FrankenPHP (Go's net/http) send the 10 KB page chunked; the others
  send `Content-Length`.

## Results: loopback, server on CCD0, wrk on CCD1

Each cell: req/s / p99 / CPUs busy on CCD0, with `-c 16N` (32 and 128 connections). The last
column is N=8 ÷ N=2 throughput.

#### hello

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 219,020 / 0.18 ms / 2.0 | 859,552 / 0.22 ms / 8.0 | 3.92× |
| swerve-ext | 215,433 / 0.30 ms / 2.0 | 845,760 / 0.33 ms / 8.1 | 3.93× |
| rr | 81,191 / 0.76 ms / 1.9 | 142,345 / 1.36 ms / 10.4 | 1.75× |
| rr+gogc400 | 82,604 / 0.47 ms / 1.8 | 143,777 / 1.15 ms / 10.4 | 1.74× |
| franken | 222,936 / 0.29 ms / 6.3 | 375,651 / 1.10 ms / 13.7 | 1.69× |
| franken+gogc400 | 241,484 / 0.24 ms / 6.1 | 456,758 / 0.84 ms / 13.9 | 1.89× |
| swoole | 255,774 / 0.25 ms / 2.0 | 1,009,278 / 0.28 ms / 8.0 | 3.95× |
| openswoole | 245,468 / 0.28 ms / 2.0 | 966,003 / 0.31 ms / 8.0 | 3.94× |
| react | 159,991 / 0.22 ms / 2.0 | 617,354 / 0.29 ms / 8.0 | 3.86× |

#### json

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 215,376 / 0.18 ms / 2.0 | 846,432 / 0.21 ms / 8.1 | 3.93× |
| swerve-ext | 211,462 / 0.21 ms / 2.0 | 836,380 / 0.33 ms / 8.0 | 3.96× |
| rr | 80,289 / 0.76 ms / 1.9 | 140,387 / 1.37 ms / 10.3 | 1.75× |
| rr+gogc400 | 81,915 / 0.48 ms / 1.8 | 142,052 / 1.17 ms / 10.4 | 1.73× |
| franken | 221,299 / 0.29 ms / 6.3 | 375,160 / 1.10 ms / 13.7 | 1.70× |
| franken+gogc400 | 239,596 / 0.24 ms / 6.0 | 455,315 / 0.84 ms / 13.9 | 1.90× |
| swoole | 252,812 / 0.25 ms / 2.0 | 989,637 / 0.29 ms / 8.0 | 3.91× |
| openswoole | 241,258 / 0.31 ms / 2.0 | 952,214 / 0.30 ms / 8.0 | 3.95× |
| react | 158,554 / 0.22 ms / 2.0 | 609,905 / 0.30 ms / 8.1 | 3.85× |

#### wait

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 3,138 / 10.23 ms / 0.0 | 12,491 / 10.27 ms / 0.2 | 3.98× |
| swerve-ext | 3,104 / 10.33 ms / 0.0 | 12,326 / 10.43 ms / 0.2 | 3.97× |
| rr | 196 / 190.38 ms / 0.0 | 781 / 161.80 ms / 0.0 | 3.98× |
| rr*16 | 3,154 / 10.20 ms / 0.2 | 12,548 / 10.32 ms / 1.2 | 3.98× |
| franken | 196 / 161.19 ms / 0.0 | 782 / 161.19 ms / 0.0 | 3.99× |
| franken*16 | 3,070 / 17.83 ms / 0.1 | 11,809 / 37.56 ms / 0.2 | 3.85× |
| swoole | 3,138 / 10.55 ms / 0.0 | 12,522 / 10.54 ms / 0.1 | 3.99× |
| openswoole | 3,141 / 10.56 ms / 0.0 | 12,503 / 10.57 ms / 0.1 | 3.98× |
| react | 3,067 / 10.55 ms / 0.1 | 12,067 / 10.69 ms / 0.3 | 3.93× |

#### page

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 58,092 / 0.63 ms / 2.0 | 212,612 / 0.88 ms / 8.0 | 3.66× |
| swerve-ext | 57,589 / 1.08 ms / 2.0 | 213,508 / 1.25 ms / 8.1 | 3.71× |
| rr | 32,354 / 1.44 ms / 2.2 | 81,601 / 2.11 ms / 9.7 | 2.52× |
| rr+gogc400 | 33,309 / 1.31 ms / 2.0 | 85,184 / 2.06 ms / 9.7 | 2.56× |
| franken | 44,923 / 0.97 ms / 2.6 | 123,954 / 1.42 ms / 10.7 | 2.76× |
| franken+gogc400 | 46,977 / 0.83 ms / 2.5 | 134,978 / 1.26 ms / 10.7 | 2.87× |
| swoole | 60,686 / 1.31 ms / 2.0 | 219,828 / 1.44 ms / 8.0 | 3.62× |
| openswoole | 56,734 / 1.58 ms / 2.0 | 215,267 / 1.45 ms / 8.0 | 3.79× |
| react | 52,732 / 0.70 ms / 2.0 | 199,697 / 0.89 ms / 8.0 | 3.79× |

**Do we get 4× from 8 workers vs 2?** Yes for the servers whose work is all in the N PHP processes:
on hello/json swerve 3.92–3.93×, swerve + ext 3.93–3.96×, Swoole 3.91–3.95×, OpenSwoole
3.94–3.95×, ReactPHP 3.85–3.86×; on the 10 KB page 3.6–3.8× (more memory traffic per request: the
shared L3 and memory bandwidth start to count). RoadRunner (1.75×) and FrankenPHP (1.70×) don't,
because their HTTP side runs in Go threads on top of the N PHP workers: at N=2 they already use
2–6 CPUs, not 2, and at N=8 10–14 of the 16. For them the ratio of workers is not a ratio of CPUs;
the CPUs column shows what each server really used.

`/wait` at 16 connections per worker only measures the client: the cooperative servers sit at
connections ÷ 10 ms (3,200 and 12,800), RoadRunner and FrankenPHP at N ÷ 10 ms (200 and 800)
because each worker blocks. With as many workers as connections (`rr*16`, `franken*16`: 32 and 128
workers) they reach the same ceiling. The next section pushes the cooperative servers to their limits.

Client headroom: the busiest run, Swoole hello at N=8 (1.01M req/s), used 29–31 % of CCD1 (no
CPU above 34 %) while its 8 workers were at 100 % (8.0 CPUs). wrk was never the limit; all
cooperative servers ran at their N CPUs, i.e. server-bound.

### `/wait` pushed to each server's limit

Connections go up (1,000 → 10,000 → 25,000 …) until throughput stops growing (less than 5 % more);
the ceiling at 1,000 and 10,000 connections is 100,000 and 1,000,000 req/s. `wrk --timeout 10s`.
Above 25,000 connections the load is split over several wrk processes (`-t16/k` each, all on CCD1),
each against its own loopback address (127.0.0.1, .2, …), so no destination needs more than the
~28,000 ephemeral ports; black's port range was not changed. p99 is the worst of the wrk processes.
Every server was CPU-saturated at 10,000 (2.0 or 8.0 CPUs) and got slower at 25,000, so the peak
is at 10,000 connections for all.

| Server | N=2 peak (10k conn) | p99 | N=8 peak (10k conn) | p99 | 8 ÷ 2 |
|---|---:|---:|---:|---:|---:|
| swerve + ext | 148,100 | 73 ms | 500,900 | 22 ms | 3.38× |
| swerve (no ext) | 171,200 * | 12 ms * | 545,800 * | 0.6–3.0 s * | – |
| Swoole | 144,600 | 74 ms | 621,000 | 20 ms | 4.29× |
| OpenSwoole | 120,400 | 88 ms | 557,400 | 21 ms | 4.63× |
| ReactPHP | 72,500 (at 1k conn) | 15 ms | 222,900 | 0.33–1.33 s | 3.07× |
| RoadRunner (1k conn) | 100 | 9.9 s (timeouts) | 701 | 2.3 s | – |
| FrankenPHP (1k conn) | 103 | 9.8 s (timeouts) | 706 | 2.2 s | – |

\* swerve without phasync-ext is not comparable above ~1,000 connections per worker: phasync's
fallback poller is `stream_select()`, capped at 1,024 descriptors per process. With 10,000
connections at N=2 each worker held 969 descriptors and the listen queue held 4,057 connections that
were never accepted; wrk's latency only counts completed requests, so its p99 looks good while
~80 % of the connections starve (and wrk logs read errors, 270–11,521). This is why swerve's own
docs point to phasync-ext for many connections.

At N=2 with 1,000 connections no server is loaded (0.8–1.4 CPUs); at 10,000 all are saturated.
RoadRunner and FrankenPHP stay at N ÷ 10 ms, so 1,000 connections queue for seconds; their
completions are halved because the requests abandoned by the warm-up's connections are still
executed first.

All runs:

| N | Server | Connections | req/s (per round) | p99 (worst wrk) | socket errors (timeouts) | server CPUs |
|---:|---|---:|---:|---:|---:|---:|
| 2 | swerve | 1,000 | 95,349 · 95,189 | 10.9 ms · 10.8 ms | 0 (0) · 0 (0) | 1.1 · 1.1 |
| 2 | swerve | 10,000 | 172,776 · 169,562 | 12.2 ms · 12.8 ms | 270 (0) · 450 (0) | 2.0 · 2.0 |
| 2 | swerve | 25,000 | 165,798 · 163,811 | 11.9 ms · 11.8 ms | 2496 (0) · 1897 (0) | 2.0 · 2.0 |
| 2 | swerve-ext | 1,000 | 73,708 · 68,220 | 15.0 ms · 14.9 ms | 0 (0) · 0 (0) | 0.9 · 0.8 |
| 2 | swerve-ext | 10,000 | 148,088 · 148,143 | 73.9 ms · 72.4 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | swerve-ext | 25,000 | 130,497 · 126,393 | 193.1 ms · 202.8 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | swoole | 1,000 | 89,071 · 89,364 | 12.6 ms · 12.6 ms | 0 (0) · 0 (0) | 0.8 · 0.8 |
| 2 | swoole | 10,000 | 143,169 · 146,110 | 73.7 ms · 73.6 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | swoole | 25,000 | 143,506 · 143,512 | 192.7 ms · 190.4 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | openswoole | 1,000 | 88,153 · 86,998 | 12.9 ms · 13.0 ms | 0 (0) · 0 (0) | 0.9 · 0.9 |
| 2 | openswoole | 10,000 | 120,205 · 120,680 | 87.0 ms · 88.9 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | openswoole | 25,000 | 92,762 · 93,996 | 336.5 ms · 597.3 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | react | 1,000 | 70,872 · 74,137 | 15.8 ms · 15.4 ms | 0 (0) · 0 (0) | 1.4 · 1.4 |
| 2 | react | 10,000 | 73,348 · 68,542 | 3410.0 ms · 3290.0 ms | 0 (0) · 0 (0) | 2.0 · 2.0 |
| 2 | rr | 1,000 | 100 | 9900.0 ms | 0 (0) | 0.0 |
| 2 | franken | 1,000 | 103 | 9790.0 ms | 0 (0) | 0.0 |
| 8 | swerve | 1,000 | 95,922 · 95,664 | 10.5 ms · 10.5 ms | 0 (0) · 0 (0) | 1.1 · 1.1 |
| 8 | swerve | 10,000 | 548,057 · 543,596 | 619.9 ms · 2980.0 ms | 1314 (0) · 2396 (0) | 8.0 · 8.0 |
| 8 | swerve | 25,000 | 528,574 · 531,114 | 695.7 ms · 17.2 ms | 11521 (0) · 11120 (0) | 8.0 · 8.1 |
| 8 | swerve-ext | 1,000 | 86,080 · 85,742 | 12.0 ms · 12.0 ms | 0 (0) · 0 (0) | 1.0 · 1.0 |
| 8 | swerve-ext | 10,000 | 501,246 · 500,471 | 22.2 ms · 22.0 ms | 0 (0) · 0 (0) | 8.0 · 8.0 |
| 8 | swerve-ext | 25,000 | 434,826 · 434,141 | 59.5 ms · 60.4 ms | 0 (0) · 0 (0) | 8.2 · 8.2 |
| 8 | swoole | 1,000 | 94,883 · 94,823 | 11.7 ms · 11.7 ms | 0 (0) · 0 (0) | 0.9 · 0.9 |
| 8 | swoole | 10,000 | 624,155 · 617,927 | 19.5 ms · 20.3 ms | 0 (0) · 0 (0) | 8.1 · 8.0 |
| 8 | swoole | 25,000 | 478,752 · 477,490 | 107.5 ms · 111.8 ms | 0 (0) · 0 (0) | 8.0 · 8.1 |
| 8 | openswoole | 1,000 | 93,806 · 93,552 | 11.9 ms · 11.9 ms | 0 (0) · 0 (0) | 0.9 · 0.9 |
| 8 | openswoole | 10,000 | 561,733 · 552,990 | 20.6 ms · 20.9 ms | 0 (0) · 0 (0) | 8.1 · 8.1 |
| 8 | openswoole | 25,000 | 405,411 · 403,918 | 128.4 ms · 122.4 ms | 0 (0) · 0 (0) | 8.1 · 8.0 |
| 8 | react | 1,000 | 85,000 · 83,586 | 12.7 ms · 12.7 ms | 0 (0) · 0 (0) | 1.6 · 1.6 |
| 8 | react | 10,000 | 223,155 · 222,636 | 1330.0 ms · 334.5 ms | 0 (0) · 0 (0) | 8.0 · 8.2 |
| 8 | react | 25,000 | 201,675 · 202,559 | 4690.0 ms · 4740.0 ms | 0 (0) · 0 (0) | 8.0 · 8.0 |
| 8 | rr | 1,000 | 701 | 2270.0 ms | 0 (0) | 0.0 |
| 8 | franken | 1,000 | 706 | 2240.0 ms | 0 (0) | 0.0 |

## WebSocket fan-out (loopback, pinned)

Servers with 8 workers on CCD0, ports 18400 and 18401. Client: `benchmarks/ws/client` (wsbench,
static Go binary) on black, on CCD1: opens the sockets at 5,000/s against 127.0.0.1, then POSTs 8
timestamped messages 1 s apart to `/publish` and times each message's arrival on every socket
(one clock). Memory per socket: the server's total RSS (every process) with the sockets open, minus
before, divided by the sockets the server holds (kernel socket buffers not included). CCD1 CPU
while the messages flow: 1–5 %, so the client was not the limit, at 50,000 sockets too.

- swerve: `benchmarks/ws/swerve.php` (`Swerve::subscribe` across workers; commit f577483, before
  778b38e changed pub/sub).
- Swoole/OpenSwoole: `apps/ws/*.php`, `WebSocket\Server` in base mode; `/publish` reaches the other
  workers through `sendMessage` (worker pipes), each worker `push()`es to its own connections.
  Framing once and `send()`ing the same bytes measured the same (`raw-wspack/`).
- ReactPHP: `apps/ws/react.php`, react/http upgrading `/news` with ratchet/rfc6455 0.4.1's
  handshake; 8 SO_REUSEPORT processes forward `/publish` to each other over unix datagram sockets
  and each frames the message once and writes it to its connections.
- RoadRunner and FrankenPHP have no in-process WebSocket server (RoadRunner's is a separate plugin
  backed by a Centrifugo server; FrankenPHP's push is the Mercure hub, server-sent events); not benchmarked.

| Server | Sockets | Delivered to every socket | p50 | p99 | last (slowest of 8 msgs) | KiB per socket | Connect phase |
|---|---:|---|---:|---:|---:|---:|---:|
| swerve + ext | 10,000 | yes | 7.0–7.3 ms | 11.0–11.2 ms | 14.6–15.1 ms | 52–53 | 7.5 s |
| Swoole | 10,000 | yes | 11.7–12.1 ms | 29.7–30.2 ms | 36.0–36.5 ms | 5 | 8.9 s |
| OpenSwoole | 10,000 | yes | 10.5–12.9 ms | 28.6–30.8 ms | 35.4–38.4 ms | 6 | 9.0 s |
| ReactPHP | 10,000 | yes | 5.7–5.9 ms | 8.9–9.1 ms | 25.4–33.6 ms | 37 | 6.9 s |
| swerve + ext | 50,000 | yes | 43.0–43.1 ms | 64.4–66.2 ms | 72.1–77.9 ms | 49–59 | 25 s |
| Swoole | 50,000 | yes | 74.8–80.4 ms | 181–187 ms | 193–199 ms | 5 | 31 s |
| OpenSwoole | 50,000 | yes | 84.5–85.0 ms | 190–191 ms | 202–203 ms | 5 | 31 s |
| ReactPHP | 50,000 | yes | 39.6–39.7 ms | 57.1–57.3 ms | 139–150 ms | 36 | 24 s |
| swerve (no ext) | 10,000 / 50,000 | **no: 8,112 connected, no message delivered** | – | – | – | – | – |

Ranges are the two rounds. swerve without phasync-ext: each of the 8 workers stops at ~1,014
sockets (the `stream_select()` limit), and the `/publish` request then never gets accepted. The
connect phase was requested at 5,000/s (10 s for 50,000); how long each server took is shown, but
connection setup was not measured as such.

Per run:

| Server | Workers | Sockets asked | Connected | Every message to every socket | p50 | p99 | last (slowest message) | KiB per socket | client CCD1 CPU |
|---|---:|---:|---:|---|---:|---:|---:|---:|---:|
| swerve (r1) | 8 | 10,000 | 8,112 | NO: no message delivered | – | – | – | 54 | – |
| swerve (r2) | 8 | 10,000 | 8,112 | NO: no message delivered | – | – | – | 59 | – |
| swerve-ext (r1) | 8 | 10,000 | 10,000 | yes | 7.3 ms | 11.2 ms | 15.1 ms | 53 | 1% |
| swerve-ext (r2) | 8 | 10,000 | 10,000 | yes | 7.0 ms | 11.0 ms | 14.6 ms | 52 | 1% |
| swoole (r1) | 8 | 10,000 | 10,000 | yes | 11.7 ms | 29.7 ms | 36.5 ms | 5 | 1% |
| swoole (r2) | 8 | 10,000 | 10,000 | yes | 12.1 ms | 30.2 ms | 36.0 ms | 5 | 1% |
| openswoole (r1) | 8 | 10,000 | 10,000 | yes | 10.5 ms | 28.6 ms | 35.4 ms | 6 | 1% |
| openswoole (r2) | 8 | 10,000 | 10,000 | yes | 12.9 ms | 30.8 ms | 38.4 ms | 6 | 1% |
| react (r1) | 8 | 10,000 | 10,000 | yes | 5.7 ms | 8.9 ms | 33.6 ms | 37 | 1% |
| react (r2) | 8 | 10,000 | 10,000 | yes | 5.9 ms | 9.1 ms | 25.4 ms | 37 | 1% |
| swerve (r1) | 8 | 50,000 | 8,112 | NO: no message delivered | – | – | – | 27 | – |
| swerve (r2) | 8 | 50,000 | 8,112 | NO: no message delivered | – | – | – | 28 | – |
| swerve-ext (r1) | 8 | 50,000 | 50,000 | yes | 43.1 ms | 66.2 ms | 77.9 ms | 59 | 3% |
| swerve-ext (r2) | 8 | 50,000 | 50,000 | yes | 43.0 ms | 64.4 ms | 72.1 ms | 49 | 3% |
| swoole (r1) | 8 | 50,000 | 50,000 | yes | 80.4 ms | 186.8 ms | 198.9 ms | 5 | 1% |
| swoole (r2) | 8 | 50,000 | 50,000 | yes | 74.8 ms | 181.2 ms | 192.9 ms | 5 | 1% |
| openswoole (r1) | 8 | 50,000 | 50,000 | yes | 85.0 ms | 190.4 ms | 202.3 ms | 5 | 1% |
| openswoole (r2) | 8 | 50,000 | 50,000 | yes | 84.5 ms | 190.8 ms | 202.8 ms | 5 | 1% |
| react (r1) | 8 | 50,000 | 50,000 | yes | 39.6 ms | 57.1 ms | 150.0 ms | 36 | 5% |
| react (r2) | 8 | 50,000 | 50,000 | yes | 39.7 ms | 57.3 ms | 139.1 ms | 36 | 5% |

## FrankenPHP: the stall is a deadlock caused by ext-parallel in the static builds

**Symptom.** With the release's static glibc binary (`frankenphp-linux-x86_64-gnu`, 1.12.7) under
`wrk -t32 -c512` over the LAN, FrankenPHP stops answering after 16–40 s of load: 0 req/s, all
connections time out, nothing is logged, a `curl` from the machine itself hangs too, and the process
sits at 0 % CPU forever. It happened with 32 workers (`num_threads` 33 or 64) and also with
**16 workers** (`num_threads` 17); the preliminary 16-worker run was lucky. JIT on or off makes no
difference.

**Reproduction.** `franken-diag/repro.sh 32 33 tracing`: starts FrankenPHP worker mode on black,
then `wrk -t32 -c512` against `/json` 4 s at a time. Stalls came after 4, 4 and 5 rounds with the
JIT, after 9 rounds without it (`repro.sh 32 33 disable`), and after 7 rounds with 16 workers
(`repro.sh 16 17 tracing`).

**Diagnosis.**
- gdb on the stalled process (`franken-diag/gdb-threads-at-stall-*.txt`): 31 of the 32 PHP threads
  block in `pthread_mutex_lock` on the same mutex, called from the SAPI's `ub_write` via
  `php_output_write` (`echo`). Disassembled, the caller is a wrapper that locks a global mutex, calls
  the saved `ub_write`, unlocks. That is **ext-parallel's** `php_parallel_output_function`
  (krakjoe/parallel `src/parallel.c`): its MINIT replaces `sapi_module.ub_write` with a version that
  serialises all output of the process through one `pthread_mutex_t`. All three static release
  builds (`-gnu`, musl, `-mimalloc`) include ext-parallel; the Docker image does not.
- The mutex owner is inside FrankenPHP's `ub_write`, which calls into Go (`go_ub_write`, a cgo
  callback), parked in the Go runtime. The Go dump (`franken-diag/goroutines-at-stall.txt.gz`,
  `GOTRACEBACK=all` + SIGABRT) shows a goroutine stuck in `runtime.gcMarkDone` "stopping the world",
  75 goroutines in "GC assist wait", and the PHP threads' callbacks runnable, waiting for the world
  to restart. A stop-the-world garbage collection and a C mutex held across a cgo callback: once
  they interleave, the process never recovers. We did not establish exactly why the stop-the-world
  cannot complete, but:
  - `GOGC=off GOMEMLIMIT=20GiB` (no GC in the test window): 20 rounds, no stall.
  - The same FrankenPHP and PHP version without ext-parallel (the Docker image's binary): 20 rounds,
    no stall, and no stall in any run since.
- The mutex also costs throughput while it works: it serialises every `echo` across all worker
  threads. Static build, PSR-15 app, median of 2 rounds (no stall in these short runs):

#### hello

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| franken | 235,713 / 0.28 ms / 6.6 | 388,011 / 1.06 ms / 13.6 | 1.65× |

#### json

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| franken | 233,871 / 0.28 ms / 6.5 | 388,809 / 1.04 ms / 13.6 | 1.66× |

#### page

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| franken | 44,355 / 1.02 ms / 2.5 | 79,902 / 2.05 ms / 5.7 | 1.80× |

  against the Docker build's hello 222,936 / 375,651 and page 44,923 / **123,954** at N=2 / N=8:
  at N=8 the static build renders the page at 64 % of the Docker build's rate, using 5.7 CPUs
  instead of 10.7.
- Ruled out as causes: `num_threads` (N+1 vs 2N), `max_threads` (not used), the JIT (stalls without
  it), GOMAXPROCS (default 32; 16 under taskset). `max_wait_time` cannot help threads blocked in C.
  Nothing in the issue tracker matches (php/frankenphp#2558 is a different wedge: a SIGSEGV'd thread
  holding a lock); not filed upstream.

For FrankenPHP users: use the Docker image or a build without ext-parallel. For the static builds,
dropping ext-parallel's `ub_write` hook would fix both the deadlock and the serialised output.

## Why the over-the-network runs were dropped

The first plan ran wrk on a second machine over the 1 Gbit LAN. Its single-queue e1000e NIC's one
softirq core caps the client at ~450k req/s, and the link caps the 10 KB page at 11.2k–11.3k req/s
(1 Gbit/s full). Round 1 (N = 4, 16, 32; `raw/lan/`) had every server but RoadRunner and FrankenPHP
at 443k–457k on hello and json from N=16, and every server at 11.2k–11.3k on the page at every N.
It separated nothing but the two Go-based servers, so round 2 was stopped and the loopback set
replaced it.

## Appendix: each server's native API, without PSR-7

Same method, from the first loopback set (`raw-native/lo/`, apps in `apps/native/`): swerve with
its own PSR-7 `Response`, RoadRunner `HttpWorker::respond()`, FrankenPHP `header()`/`echo`,
Swoole/OpenSwoole `$response->end()`, ReactPHP react/http's own response; no PSR-7 objects
otherwise. The difference to the tables above is what the PSR-7 layer costs each server.

#### hello

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 229,894 / 0.16 ms / 2.0 | 902,238 / 0.19 ms / 8.0 | 3.92× |
| swerve-ext | 224,350 / 0.29 ms / 2.0 | 875,363 / 0.30 ms / 8.0 | 3.90× |
| rr | 92,267 / 0.67 ms / 1.9 | 180,373 / 1.13 ms / 10.6 | 1.95× |
| franken | 372,671 / 0.23 ms / 9.6 | 393,894 / 1.12 ms / 13.3 | 1.06× |
| swoole | 370,747 / 0.20 ms / 2.0 | 1,517,528 / 0.18 ms / 8.1 | 4.09× |
| openswoole | 338,353 / 0.21 ms / 2.0 | 1,392,573 / 0.20 ms / 8.0 | 4.12× |
| react | 190,289 / 0.19 ms / 2.0 | 740,023 / 0.23 ms / 8.0 | 3.89× |

#### json

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 227,449 / 0.16 ms / 2.0 | 889,955 / 0.20 ms / 8.0 | 3.91× |
| swerve-ext | 221,696 / 0.33 ms / 2.0 | 866,532 / 0.29 ms / 8.0 | 3.91× |
| rr | 91,397 / 0.68 ms / 1.9 | 177,120 / 1.15 ms / 10.5 | 1.94× |
| franken | 369,130 / 0.23 ms / 9.5 | 393,161 / 1.12 ms / 13.3 | 1.07× |
| swoole | 361,357 / 0.20 ms / 2.0 | 1,485,644 / 0.18 ms / 8.1 | 4.11× |
| openswoole | 335,939 / 0.20 ms / 2.0 | 1,361,447 / 0.21 ms / 8.1 | 4.05× |
| react | 188,497 / 0.19 ms / 2.0 | 733,942 / 0.23 ms / 8.0 | 3.89× |

#### wait

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 3,128 / 10.22 ms / 0.0 | 12,506 / 10.23 ms / 0.2 | 4.00× |
| swerve-ext | 3,106 / 10.32 ms / 0.0 | 12,291 / 10.45 ms / 0.1 | 3.96× |
| rr | 195 / 218.40 ms / 0.0 | 781 / 161.59 ms / 0.0 | 3.99× |
| franken | 197 / 191.81 ms / 0.0 | 783 / 164.16 ms / 0.0 | 3.98× |
| swoole | 3,155 / 10.47 ms / 0.0 | 12,547 / 10.43 ms / 0.1 | 3.98× |
| openswoole | 3,140 / 10.50 ms / 0.0 | 12,525 / 10.55 ms / 0.1 | 3.99× |
| react | 3,077 / 10.57 ms / 0.1 | 12,112 / 10.69 ms / 0.3 | 3.94× |

#### page

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 58,652 / 0.59 ms / 2.0 | 216,949 / 0.85 ms / 8.0 | 3.70× |
| swerve-ext | 58,283 / 1.24 ms / 2.0 | 211,897 / 1.43 ms / 8.0 | 3.64× |
| rr | 34,741 / 1.35 ms / 2.2 | 90,055 / 1.94 ms / 9.8 | 2.59× |
| franken | 50,848 / 0.86 ms / 2.7 | 139,141 / 1.25 ms / 10.7 | 2.74× |
| swoole | 65,820 / 1.09 ms / 2.0 | 236,158 / 1.54 ms / 8.1 | 3.59× |
| openswoole | 60,328 / 1.43 ms / 2.0 | 227,826 / 1.56 ms / 8.0 | 3.78× |
| react | 55,889 / 0.64 ms / 2.0 | 211,570 / 0.92 ms / 8.1 | 3.79× |


## Things that looked wrong, and how they were checked

- FrankenPHP at N=2 native hello: 372k req/s from 2 workers, more than swerve's 230k. Its Go side
  used 9.6 CPUs, not 2 — the reason for the CPUs column.
- FrankenPHP static vs Docker build: above; all FrankenPHP numbers in the tables are the Docker build.
- RoadRunner's request codec is slow without ext-protobuf; the extension was loaded.
- ReactPHP's default `StreamSelectLoop` is capped at 1,024 descriptors and its default
  `HttpServer` limits concurrency from `memory_limit`/`post_max_size`; ext-ev and
  `StreamingRequestMiddleware` were used.
- SWOOLE_BASE vs SWOOLE_PROCESS was measured, not assumed (the first comparison accidentally ran
  base twice through an environment-variable clash; it was caught and rerun).
- Leftovers: `srv.sh stop` kills the process group plus all descendants and checks that no process
  of the group remains and ports 18500/18400/18401 are free. One early mistake: a `pkill -f` pattern
  matched the harness's own shell; it killed the local runner, not anything on black, and black was
  verified clean afterwards.
- Every script holds `~/bench/fpm-bench/bench.lock` on black, the lock the other benchmark scripts
  there take, so no two benchmarks overlapped.

## Files

- `bench.sh`: the HTTP runs (`lo` = the main set; `lan` = dropped). Server names may carry variants:
  `rr*16` = 16N workers, `franken+gogc400` = `GOGC=400`.
- `waitmax.sh`: `/wait` to the limit. `ws.sh`: WebSocket fan-out (`LO=1` for the pinned loopback set).
- `srv.sh` (runs on black): starts one server with all its settings (optionally under taskset);
  `stop`, `cpus`, `rss`.
- `summarize.py`: the tables from the raw output (`lo`, `lan`, `waitmax`, `ws`).
- `apps/`: the PSR-15 handler, page template and Swoole bridge in `common/`, per-server adapters
  `*.php` (dependencies in `composer.json`), `native/` the appendix's apps, `ws/` the WebSocket servers.
- Raw output: `raw/lo/` (main), `raw/waitmax/`, `raw/ws-lo/`, `raw-variants/` (GOGC, 16N workers),
  `raw-static/` (FrankenPHP static build), `raw-native/` (appendix), `raw-tune/` (Swoole mode,
  FrankenPHP threads), `raw-wspack/`, `raw/lan/` (dropped).
- `franken-diag/`: reproduction script, gdb thread dumps, goroutine dump.

Rerun (from the client machine, with the tools on black as described above):

```bash
./bench.sh lo "$PWD/raw" "swerve swerve-ext rr franken swoole openswoole react" "2 8" "hello json wait page" 2
./waitmax.sh "$PWD/raw" "swerve swerve-ext swoole openswoole react" "2 8" "1000 10000 25000 50000 100000" 2
LO=1 ./ws.sh "$PWD/raw" "swerve swerve-ext swoole openswoole react" "8:10000 8:50000" 2
python3 summarize.py raw lo; python3 summarize.py raw waitmax; python3 summarize.py raw ws
```
