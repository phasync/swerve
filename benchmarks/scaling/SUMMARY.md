# PHP-FPM against swerve, 4 to 32 processes

2026-09-28. Server **black**: Ryzen 9 9950X3D (16 cores, 32 threads), PHP 8.5.11, Linux 6.17. Client
**home**: 2 × Xeon E5-2697 v3, `wrk -t32 -c16N -d10s --latency` after a 3 s warm-up, over 1 Gbit/s
Ethernet. swerve 0.1.0-alpha16, phasync 2.0.0-alpha12, phasync-ext 0.5.0-alpha10; nginx 1.24 in front
of FPM (`pm = static`, N children, nginx workers = min(N, 8)). Opcache and tracing JIT (128M) in
both; FPM with `validate_timestamps=0`, swerve too. Every application in production mode, as its
adapter's `benchmarks/run.sh` prepares it (`setup.sh`).

Routes: **json** the adapter's JSON route (plain: `/hello`, "Hello"); **session** a page that
reads and writes the session, each wrk thread cycling through 64 sessions of its own (2048
made beforehand, `session.lua`); **wait** `usleep(10000)` then a small JSON body, standing in
for a query (`/usleep?ms=10`). Cells: requests/s (p99).

- `*` the 1 Gbit/s link is full (> 85%): the page's size, not the server, sets the rate
- `‡` the client's NIC core is saturated (a single-queue e1000e: one core takes every interrupt,
  up to 100% softirq): a lower bound, and ratios against FPM are compressed
- `†` wrk timeouts (see below)

### plain

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 199,325 (567.00us)‡ | 289,425 (388.00us)‡ | 291,856 (386.00us)‡ |
| json | 16 | 302,795 (1.30ms)‡ | 441,069 (761.00us)‡ | 443,147 (755.00us)‡ |
| json | 32 | 367,708 (2.56ms)‡ | 453,512 (1.36ms)‡ | 454,882 (1.35ms)‡ |
| wait | 4 | 393 (161.70ms) | 393 (212.03ms) | 5,925 (11.19ms) |
| wait | 16 | 1,585 (161.58ms) | 1,586 (231.97ms) | 23,887 (11.15ms) |
| wait | 32 | 3,168 (161.61ms) | 3,166 (233.62ms) | 47,351 (11.23ms) |

Scaling, swerve/FPM at N=4/16/32: json 1.5× / 1.5× / 1.2×; wait 1.0× / 1.0× / 1.0× (with ext 15.1× / 15.1× / 14.9×)

### laravel

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 5,089 (13.18ms) | 9,820 (31.44ms) | 10,097 (28.79ms) |
| json | 16 | 17,001 (15.57ms) | 35,062 (20.98ms) | 34,681 (22.95ms) |
| json | 32 | 22,360 (24.60ms) | 53,275 (17.01ms) | 53,071 (17.48ms) |
| session | 4 | 962 (133.90ms) | 3,485 (57.41ms) | 3,440 (28.68ms) |
| session | 16 | 5,422 (67.12ms) | 7,086 (145.64ms) | 6,951 (147.47ms) |
| session | 32 | 6,683 (130.02ms) | 8,119 (305.13ms) | 8,416 (284.58ms) |
| wait | 4 | 364 (177.19ms) | 376 (232.59ms) | 376 (307.76ms) |
| wait | 16 | 1,464 (176.83ms) | 1,516 (211.60ms) | 1,511 (222.77ms) |
| wait | 32 | 2,907 (177.24ms) | 3,005 (286.11ms) | 3,004 (287.22ms) |

Scaling, swerve/FPM at N=4/16/32: json 1.9× / 2.1× / 2.4×; session 3.6× / 1.3× / 1.2×; wait 1.0× / 1.0× / 1.0× (with ext 1.0× / 1.0× / 1.0×)

### symfony

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 7,581 (9.09ms) | 44,901 (29.29ms) | 46,600 (38.31ms) |
| json | 16 | 22,721 (11.91ms) | 159,917 (39.35ms)‡ | 150,213 (47.91ms)‡ |
| json | 32 | 29,593 (18.58ms) | 228,947 (47.45ms)‡ | 207,943 (66.70ms)‡ |
| session | 4 | 665 (200.73ms) | 7,110 (89.50ms) | 7,057 (94.76ms) |
| session | 16 | 5,994 (76.61ms) | 9,002 (379.69ms) | 9,020 (309.32ms) |
| session | 32 | 6,949 (148.16ms) | 9,796 (502.67ms) | 9,131 (548.38ms) |
| wait | 4 | 369 (172.97ms) | 388 (326.32ms) | 4,875 (22.41ms) |
| wait | 16 | 1,496 (172.65ms) | 1,566 (316.01ms) | 21,234 (22.64ms) |
| wait | 32 | 2,975 (173.18ms) | 3,126 (356.85ms) | 41,035 (22.71ms) |

Scaling, swerve/FPM at N=4/16/32: json 5.9× / 7.0× / 7.7×; session 10.7× / 1.5× / 1.4×; wait 1.1× / 1.0× / 1.1× (with ext 13.2× / 14.2× / 13.8×)

### yii

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 3,736 (17.82ms) | 104,599 (836.00us) | 101,930 (1.27ms) |
| json | 16 | 11,975 (22.69ms) | 369,177 (1.32ms)‡ | 362,960 (1.76ms)‡ |
| json | 32 | 16,216 (33.30ms) | 437,783 (1.89ms)‡ | 437,089 (2.39ms)‡ |
| session | 4 | 3,140 (21.22ms) | 7,320 (11.10ms)* | 7,321 (11.72ms)* |
| session | 16 | 7,287 (49.49ms)* | 7,314 (47.34ms)* | 7,314 (44.29ms)* |
| session | 32 | 7,296 (109.68ms)* | 7,323 (92.95ms)* | 7,322 (91.67ms)* |
| session-counter | 4 | 3,637 (18.43ms) | 77,607 (1.17ms) | 75,914 (1.52ms) |
| session-counter | 16 | 11,707 (23.10ms) | 270,777 (1.54ms)‡ | 279,020 (2.11ms)‡ |
| session-counter | 32 | 16,039 (33.73ms) | 355,622 (3.53ms)‡ | 349,491 (3.99ms)‡ |
| wait | 4 | 357 (181.47ms) | 391 (203.25ms) | 384 (196.55ms) |
| wait | 16 | 1,425 (183.91ms) | 1,580 (233.48ms) | 1,546 (238.00ms) |
| wait | 32 | 2,819 (182.25ms) | 3,148 (284.31ms)† | 3,093 (227.38ms) |

Scaling, swerve/FPM at N=4/16/32: json 28.0× / 30.8× / 27.0×; session 2.3× / 1.0× / 1.0×; session-counter 21.3× / 23.1× / 22.2×; wait 1.1× / 1.1× / 1.1× (with ext 1.1× / 1.1× / 1.1×)

### cakephp

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 10,214 (6.77ms) | 45,047 (4.66ms) | 43,834 (4.23ms) |
| json | 16 | 29,878 (9.23ms) | 164,789 (4.45ms)‡ | 158,926 (4.77ms)‡ |
| json | 32 | 38,063 (14.71ms) | 214,951 (7.24ms)‡ | 214,118 (7.26ms)‡ |
| session | 4 | 9,710 (7.32ms) | 33,572 (4.55ms) | 33,180 (4.26ms) |
| session | 16 | 28,863 (9.47ms) | 124,960 (4.47ms)‡ | 120,409 (5.24ms)‡ |
| session | 32 | 37,066 (14.93ms) | 170,606 (7.80ms)‡ | 169,501 (8.39ms)‡ |
| wait | 4 | 376 (170.43ms) | 388 (216.09ms) | 383 (218.39ms) |
| wait | 16 | 1,521 (169.85ms) | 1,566 (245.59ms) | 1,548 (227.72ms) |
| wait | 32 | 3,024 (169.89ms) | 3,121 (245.42ms) | 3,086 (237.56ms) |

Scaling, swerve/FPM at N=4/16/32: json 4.4× / 5.5× / 5.6×; session 3.5× / 4.3× / 4.6×; wait 1.0× / 1.0× / 1.0× (with ext 1.0× / 1.0× / 1.0×)

### spiral

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 918 (71.88ms) | 10,584 (8.12ms) | 10,478 (14.38ms) |
| json | 16 | 3,280 (84.21ms) | 36,866 (10.32ms) | 36,912 (15.45ms) |
| json | 32 | 4,284 (132.88ms) | 49,638 (15.87ms) | 49,532 (22.89ms) |
| session | 4 | 890 (75.31ms) | 7,766 (11.53ms) | 6,627 (13.43ms) |
| session | 16 | 3,214 (83.16ms) | 27,688 (15.17ms) | 20,086 (18.52ms) |
| session | 32 | 4,202 (128.62ms) | 39,445 (19.99ms) | 32,767 (25.51ms) |
| wait | 4 | 267 (240.49ms) | 378 (233.24ms) | 375 (245.22ms) |
| wait | 16 | 1,084 (240.77ms) | 1,521 (211.39ms) | 1,510 (276.06ms) |
| wait | 32 | 2,104 (247.33ms) | 3,027 (253.71ms)† | 3,014 (275.76ms) |

Scaling, swerve/FPM at N=4/16/32: json 11.5× / 11.2× / 11.6×; session 8.7× / 8.6× / 9.4×; wait 1.4× / 1.4× / 1.4× (with ext 1.4× / 1.4× / 1.4×)

### codeigniter

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 5,874 (11.86ms) | 10,590 (7.79ms) | 8,688 (8.84ms) |
| json | 16 | 17,550 (16.13ms) | 37,248 (10.73ms) | 25,834 (16.21ms) |
| json | 32 | 24,064 (22.67ms) | 50,364 (15.37ms) | 36,109 (21.10ms) |
| session | 4 | 4,998 (13.62ms) | 8,453 (9.31ms) | 6,994 (11.13ms) |
| session | 16 | 15,403 (18.06ms) | 29,764 (13.07ms) | 20,207 (17.80ms) |
| session | 32 | 21,454 (25.75ms) | 42,223 (17.95ms) | 29,873 (27.21ms) |
| wait | 4 | 367 (174.47ms) | 379 (222.54ms) | 372 (203.51ms) |
| wait | 16 | 1,479 (175.07ms) | 1,519 (243.36ms) | 1,498 (257.00ms) |
| wait | 32 | 2,936 (175.91ms) | 3,027 (275.91ms)† | 2,970 (268.74ms) |

Scaling, swerve/FPM at N=4/16/32: json 1.8× / 2.1× / 2.1×; session 1.7× / 1.9× / 2.0×; wait 1.0× / 1.0× / 1.0× (with ext 1.0× / 1.0× / 1.0×)

### laminas

| route | N | FPM | swerve | swerve + ext |
|---|---:|---:|---:|---:|
| json | 4 | 11,938 (6.00ms) | 12,468 (6.11ms) | 12,087 (11.21ms) |
| json | 16 | 29,746 (9.69ms) | 44,280 (9.33ms) | 43,760 (13.17ms) |
| json | 32 | 41,895 (13.10ms) | 62,019 (13.56ms) | 60,117 (20.02ms) |
| session | 4 | 9,563 (7.31ms) | 8,661 (9.69ms) | 8,530 (16.73ms) |
| session | 16 | 25,798 (10.96ms) | 34,506 (11.08ms) | 34,298 (16.46ms) |
| session | 32 | 36,498 (14.99ms) | 51,513 (16.23ms) | 50,801 (22.41ms) |
| wait | 4 | 379 (168.42ms) | 379 (210.82ms) | 376 (211.95ms) |
| wait | 16 | 1,525 (169.17ms) | 1,526 (231.31ms) | 1,515 (232.98ms) |
| wait | 32 | 3,044 (168.97ms) | 3,035 (293.32ms)† | 3,018 (253.83ms) |

Scaling, swerve/FPM at N=4/16/32: json 1.0× / 1.5× / 1.5×; session 0.9× / 1.3× / 1.4×; wait 1.0× / 1.0× / 1.0× (with ext 1.0× / 1.0× / 1.0×)

## What looked wrong

- **CakePHP under swerve, N ≥ 16: rerun after a phasync fix.** The first runs measured swap:
  each worker grew about 10 KB per request, to about 1.2 GB, because phasync collected cyclic
  garbage only after some coroutine ended, and swerve's kept-alive connections end none
  (`gc_status()` after 42k requests: 7 collections, 655 MB held against 22 MB live). phasync
  2.0.0-alpha14 also collects at PHP's own threshold of possible cycles (phasync/phasync#52); the
  CakePHP rows above are the rerun with it (swerve 0.1.0-alpha19), without swapping.
- **Only Symfony and plain swerve overlap waits with phasync-ext.** Laravel, Yii, CakePHP,
  Spiral, CodeIgniter and Laminas serve one request at a time per worker
  (`phasync\Util\Synchronized`, as their READMEs say), so their wait rows match FPM. Symfony's
  pool of up to 16 kernels per worker gives 13–14× FPM.
- **Laravel and Symfony session pages are SQLite-bound.** FPM's CPU is mostly idle. Each request
  opens and closes its SQLite connection, and the last close checkpoints and deletes the WAL:
  about 3 disk writes per request, against about 1.5 under swerve, whose persistent connection
  keeps the WAL. Beyond N=4 both servers wait on SQLite's single writer.
- **Yii's session page is its 15.9 KB home page**, which fills the link at about 7,300 req/s for
  either server (`*`). `session-counter` is Yii's small JSON `/counter`, also with a session.
- **Plain, Yii and Symfony JSON at N ≥ 16 are client-bound (`‡`).** Black's busiest softirq core
  never went above 28%.
- **Blocking routes have a wider tail under swerve**: p99 203–357 ms against FPM's 162–182 ms (Spiral 240–247),
  and 1–5 wrk timeouts (> 2 s) at N=32, with the same throughput and mean. A keep-alive
  connection stays on its worker, and the workers carry unequal shares. nginx queues for every
  FPM child.
- **CodeIgniter, and Spiral's session page, are slower with phasync-ext**: 17–32% and 15–27%
  below plain swerve. The difference repeats at every N, so it isn't noise.
- Ruled out: qdisc drops on black's eno1 were 0 in all 216 runs. Every server stopped by PID,
  and nothing was left (`stop()` checks the session and the port; `problems.txt` was never
  written). The runs held `~/bench/fpm-bench/bench.lock`. Sessions were checked per server
  (`raw/*.session-check.txt`: the counter increments, 2048 distinct cookies). Spiral's cookies
  are `Secure`, which curl's cookie jar drops over http, so cookies come from the `Set-Cookie`
  headers. JIT had to go on FPM's command line: `php_admin_value` in the pool left it off, since
  the master allocates the buffer. Black is a desktop, with obs, opencode and a chatter poller
  running during the runs.

## Files

`setup.sh` builds the applications on black. `run.sh` runs the matrix there, and ssh's back here
for `client.sh` (wrk, session cookies, this machine's CPU). `summarize.py` makes these tables from
`raw/`. `leak.sh` measures memory growth per request.
`~/bench/fpm-bench/serve.sh` and `/home/frode/dev/fpm-bench/serve.sh` now take `BIND` (default
127.0.0.1) and `JIT`.
