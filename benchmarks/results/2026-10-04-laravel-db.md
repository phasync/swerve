# Laravel with a database: Swerve, FrankenPHP and RoadRunner

Measured 2026-10-04 on `black`. Same Laravel app, same MySQL and Redis, every server on its own native HTTP listener (no proxy), all clients on `wrk`. Best of three 30 s runs after a 5 s warm-up; all 288 runs are logged in `~/bench/results-docs-*.{raw,sum}` on black.

## Result

Swerve with phasync-ext runs a request that waits on the database without holding its worker. Four workers therefore do what the other servers need dozens of workers for:

| `/db/page`, c256, 4 workers | Swerve (ext) | Swerve (no ext) | FrankenPHP | RoadRunner |
|---|---:|---:|---:|---:|
| no added DB wait | **6253** | 1388 | 1458 | 1297 |
| +2 ms per query | **5781** | 367 | 369 | 362 |
| +5 ms per query | **5606** | 195 | 196 | 194 |

req/s. Swerve's throughput falls 10 % from no wait to 5 ms per query; the others fall 87 %. To reach Swerve's 5606 req/s at 5 ms, FrankenPHP and RoadRunner scale with their worker count (the matrix stops at 64):

| `/db/page`, c256, +5 ms | Workers | req/s | RSS | MySQL connections |
|---|---:|---:|---:|---:|
| Swerve (ext) | 4 | 5606 | 207 MB | 271 |
| FrankenPHP | 64 | 3152 | 513 MB | 64 |
| RoadRunner | 64 | 2656 | 3.8 GB | 64 |

## What it does not show

- **Without waiting, the lead is small.** At no added DB wait, 4 Swerve workers (6253) are ahead of FrankenPHP at 32 workers (5417) by 15 %; both are CPU-bound. Swerve's lead is the waiting, not raw speed.
- **Without the ext, Swerve is equal to FrankenPHP and RoadRunner**, as expected from one request per worker: within 2 % of both once there is a DB wait, and at no wait 5 % behind FrankenPHP and 7 % ahead of RoadRunner. Its p99 is worse: 224 ms vs 189 ms (no wait, c256), 519 ms vs 321 ms (+5 ms, c64).
- **Swerve holds far more MySQL connections** (up to 281 vs 4 at N=4), because each waiting request holds its own. A MySQL with `max_connections` below that limits Swerve's concurrency. The matrix ran with 2000.
- **FrankenPHP and RoadRunner were not pushed past 64 workers.** Their throughput still doubled with each doubling of workers at 5 ms (802, 1593, 3152 for FrankenPHP), so where they level off is not shown.
- The wait is simulated: each query is preceded by `DO SLEEP(n/1000)` on the same connection. It models a slower database or network, not a measured one.

## Setup

- **Machine:** AMD Ryzen 9 9950X3D (32 threads), kernel 6.17, PHP 8.5.11 NTS with opcache and the tracing JIT (128 MB), Laravel 13.34.
- **Pinning:** servers on cores 0-3; `wrk` 4.1.0 on idle cores in 8-15, c64 or c256 connections.
- **Servers:** Swerve (`swerve-laravel` Handler, `Swerve::virtualize()` with phasync-ext 0.5.0-beta1, or without the ext); FrankenPHP 1.12.7 and RoadRunner 2025.1.15 through Laravel Octane in worker mode.
- **Data:** MySQL 8.0.46 and Redis 7.0.15 on the home server over the LAN, identical for all servers; `max_connections` 2000.
- **Code under test:** phasync `a165fbc`, phasync-ext `e95819f`, swerve `ee1efd0` and swerve-laravel `a5594b3`, both with uncommitted changes (virtualization moved into Swerve; streamed responses run inline). The same code on black was checksum-checked against the repos before the run.
- **Checks:** before the run every server returned the same md5 for both routes; no run had a non-2xx response, and MySQL logged no "Too many connections". The only socket errors were 2-4 read errors on three N=1 no-ext cells, which have 5 s of queueing latency.

## The application

A stock Laravel 13.34.0 skeleton with Laravel Octane 2.20 (needed by FrankenPHP and RoadRunner) and `predis`. The same app, routes, controller and views are copied byte for byte to each server's directory; only the entry point differs.

- **Environment:** `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=error`.
- **Routing:** the default `bootstrap/app.php` (web, console, `/up` health). The benchmark routes live in `routes/db.php` with `withoutMiddleware('web')`, so no session, cookie or CSRF middleware runs.
- **Controller:** one `DbController` with `page()` and `cache()`. It uses the query builder on the `mysql` connection (no Eloquent models) and a Blade view with an included row partial, rendering 20 rows.
- **Data:** `bench_authors` (500 rows) and `bench_posts` (20000 rows, indexes on `(category_id, score, id)` and `author_id`), seeded deterministically; the Redis cache holds one key. Every page is a pure function of the seed, so its md5 is the same on every server.
- **Entry points:**
  - Swerve: `swerve.php` calls `Swerve::virtualize()` when phasync-ext is loaded and creates `Swerve\Laravel\Handler` (the Pool of application instances, 60 s idle trim). One Swerve process per worker, each serving many concurrent requests.
  - FrankenPHP: `octane:start --server=frankenphp` with a Caddyfile (`num_threads` 65, worker mode).
  - RoadRunner: `octane:start --server=roadrunner`.
  - Octane's `--max-requests` is set so high that no worker restarts during a run.

## Method

1. Start the server on cores 0-3 with N workers; wait until it answers.
2. Check `/db/page` and `/db/cache` return 200 and the expected md5 (`CHECK` lines in the raw logs); seed the cache.
3. For each cell: 5 s `wrk` warm-up, then a 30 s measured `wrk --latency` run against the server directly (no proxy), three times; the table reports the best run and the spread.
4. During the measured run: server CPU (ticks of the server's process group), RSS/PSS of all server processes, and peak MySQL connections (sampled from the database).
5. Every cell is logged (raw `wrk` output and a summary line per run); a preflight and a short pilot validated the harness before the full run (about 3 h).

## Workload

One Laravel route set, identical in every app:

- `/db/page`: three MySQL queries (a row by id, a join with `ORDER BY ... LIMIT 20`, an aggregate), rendered through a Blade view.
- `/db/cache`: the first two queries, and the aggregate from the Laravel Redis cache (`predis`). `Swerve (ext, built-in cache)` reads it from `Swerve::cache()` instead.
- `DB wait`: `DO SLEEP(n)` before every query, so a page costs three waits and a cache page two.

## Effect of moving virtualization into Swerve

The same cells ran earlier in a pilot (one rep, before the change; both direct):

| Cell | Pilot | Now |
|---|---:|---:|
| ext, no wait, c64 | 5280 | 5809 |
| ext, no wait, c256 | 5670 | 6253 |
| ext, +5 ms, c64 | 2955 | 2955 |
| ext, +5 ms, c256 | 5356 | 5606 |
| no ext, no wait, c64 | 1357 | 1407 |
| no ext, +5 ms, c64 | 199 | 202 |

Higher where the CPU is the limit, unchanged where the wait is. The pilot was a single run, so the size of the gain is indicative.

## Reproducing

On black: `~/bench/run-docs.sh` (about 3 h; preflight first, progress in `~/bench/docs-run.progress`). Blocks: A (N=4, all servers, DB 0/2/5, c64 and c256, both routes), A2 (built-in cache), B (N=1 and 2), C (16 and 32 workers for FrankenPHP and RoadRunner), C2 (64 workers, +5 ms).

## Results

Columns: best of three; spread is the lowest to the highest of the three; p50 and p99 are those of the best run; CPU is the server processes' average cores; RSS is all server processes; MySQL conns is the peak.

### Four workers, `/db/page`

| DB wait | c | Server | req/s (best of 3) | spread | p50 | p99 | CPU cores | MySQL conns | RSS MB |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|
| 0 ms | 64 | Swerve (ext) | **5809** | 5783–5809 | 10.80ms | 17.65ms | 3.87 | 75 | 136 |
| 0 ms | 64 | Swerve (no ext) | **1407** | 1358–1407 | 45.51ms | 64.04ms | 0.91 | 4 | 113 |
| 0 ms | 64 | FrankenPHP | **1432** | 1417–1432 | 44.53ms | 51.77ms | 1.00 | 4 | 216 |
| 0 ms | 64 | RoadRunner | **1289** | 1267–1289 | 49.38ms | 53.94ms | 1.08 | 4 | 339 |
| 0 ms | 256 | Swerve (ext) | **6253** | 6210–6253 | 41.00ms | 54.52ms | 4.00 | 281 | 213 |
| 0 ms | 256 | Swerve (no ext) | **1388** | 1374–1388 | 188.40ms | 224.28ms | 0.90 | 4 | 117 |
| 0 ms | 256 | FrankenPHP | **1458** | 1425–1458 | 174.71ms | 189.25ms | 1.02 | 4 | 235 |
| 0 ms | 256 | RoadRunner | **1297** | 1255–1297 | 195.89ms | 211.28ms | 1.09 | 4 | 354 |
| 2 ms | 64 | Swerve (ext) | **4306** | 4276–4306 | 14.71ms | 18.67ms | 3.15 | 69 | 133 |
| 2 ms | 64 | Swerve (no ext) | **371** | 369–371 | 182.82ms | 199.22ms | 0.26 | 4 | 113 |
| 2 ms | 64 | FrankenPHP | **373** | 371–373 | 171.17ms | 177.49ms | 0.28 | 4 | 219 |
| 2 ms | 64 | RoadRunner | **369** | 366–369 | 173.44ms | 178.64ms | 0.33 | 4 | 338 |
| 2 ms | 256 | Swerve (ext) | **5781** | 5764–5781 | 43.34ms | 66.74ms | 3.99 | 279 | 214 |
| 2 ms | 256 | Swerve (no ext) | **367** | 365–367 | 683.13ms | 886.74ms | 0.26 | 4 | 128 |
| 2 ms | 256 | FrankenPHP | **369** | 366–369 | 683.41ms | 831.75ms | 0.28 | 4 | 238 |
| 2 ms | 256 | RoadRunner | **362** | 360–362 | 695.43ms | 884.47ms | 0.33 | 4 | 354 |
| 5 ms | 64 | Swerve (ext) | **2955** | 2922–2955 | 21.58ms | 23.75ms | 2.18 | 72 | 134 |
| 5 ms | 64 | Swerve (no ext) | **202** | 201–202 | 263.66ms | 518.55ms | 0.14 | 4 | 114 |
| 5 ms | 64 | FrankenPHP | **203** | 202–203 | 314.67ms | 320.50ms | 0.16 | 4 | 216 |
| 5 ms | 64 | RoadRunner | **200** | 200–200 | 318.23ms | 323.79ms | 0.18 | 4 | 337 |
| 5 ms | 256 | Swerve (ext) | **5606** | 5578–5606 | 44.82ms | 61.55ms | 3.93 | 271 | 207 |
| 5 ms | 256 | Swerve (no ext) | **195** | 195–195 | 1.29s | 2.05s | 0.14 | 4 | 128 |
| 5 ms | 256 | FrankenPHP | **196** | 195–196 | 1.26s | 1.99s | 0.16 | 4 | 235 |
| 5 ms | 256 | RoadRunner | **194** | 194–194 | 1.27s | 2.01s | 0.18 | 4 | 351 |

### Four workers, `/db/cache`

| DB wait | c | Server | req/s (best of 3) | spread | p50 | p99 | CPU cores | MySQL conns | RSS MB |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|
| 0 ms | 64 | Swerve (ext) | **6151** | 6108–6151 | 10.14ms | 15.42ms | 3.95 | 83 | 141 |
| 0 ms | 64 | Swerve (ext, built-in cache) | **5989** | 5947–5989 | 11.01ms | 17.72ms | 3.90 | 66 | 130 |
| 0 ms | 64 | Swerve (no ext) | **1553** | 1548–1553 | 42.58ms | 53.06ms | 0.99 | 4 | 113 |
| 0 ms | 64 | FrankenPHP | **1573** | 1559–1573 | 40.70ms | 43.88ms | 1.08 | 4 | 216 |
| 0 ms | 64 | RoadRunner | **1461** | 1449–1461 | 43.66ms | 47.10ms | 1.21 | 4 | 341 |
| 0 ms | 256 | Swerve (ext) | **6456** | 6438–6456 | 40.36ms | 59.84ms | 4.00 | 278 | 220 |
| 0 ms | 256 | Swerve (ext, built-in cache) | **6316** | 6297–6316 | 39.33ms | 64.97ms | 3.99 | 305 | 223 |
| 0 ms | 256 | Swerve (no ext) | **1547** | 1538–1547 | 170.77ms | 187.86ms | 0.99 | 4 | 117 |
| 0 ms | 256 | FrankenPHP | **1569** | 1550–1569 | 162.88ms | 173.66ms | 1.08 | 4 | 235 |
| 0 ms | 256 | RoadRunner | **1460** | 1450–1460 | 175.27ms | 186.52ms | 1.21 | 4 | 352 |
| 2 ms | 64 | Swerve (ext) | **5253** | 5236–5253 | 11.75ms | 16.65ms | 3.71 | 78 | 139 |
| 2 ms | 64 | Swerve (ext, built-in cache) | **5206** | 5146–5206 | 12.07ms | 17.38ms | 3.54 | 79 | 135 |
| 2 ms | 64 | Swerve (no ext) | **542** | 539–542 | 114.11ms | 151.41ms | 0.36 | 4 | 114 |
| 2 ms | 64 | FrankenPHP | **546** | 541–546 | 117.07ms | 120.71ms | 0.40 | 4 | 219 |
| 2 ms | 64 | RoadRunner | **534** | 531–534 | 119.66ms | 123.02ms | 0.47 | 4 | 340 |
| 2 ms | 256 | Swerve (ext) | **6149** | 6124–6149 | 41.07ms | 56.99ms | 4.00 | 289 | 217 |
| 2 ms | 256 | Swerve (ext, built-in cache) | **6131** | 6079–6131 | 40.80ms | 70.04ms | 3.94 | 295 | 215 |
| 2 ms | 256 | Swerve (no ext) | **538** | 535–538 | 455.14ms | 538.41ms | 0.37 | 4 | 128 |
| 2 ms | 256 | FrankenPHP | **541** | 538–541 | 469.52ms | 476.86ms | 0.40 | 4 | 229 |
| 2 ms | 256 | RoadRunner | **529** | 525–529 | 479.22ms | 500.18ms | 0.47 | 4 | 352 |
| 5 ms | 64 | Swerve (ext) | **4081** | 4051–4081 | 15.62ms | 17.91ms | 2.94 | 80 | 139 |
| 5 ms | 64 | Swerve (ext, built-in cache) | **4118** | 3970–4118 | 15.44ms | 18.11ms | 2.91 | 67 | 131 |
| 5 ms | 64 | Swerve (no ext) | **299** | 298–299 | 217.41ms | 243.54ms | 0.21 | 4 | 114 |
| 5 ms | 64 | FrankenPHP | **300** | 299–300 | 213.59ms | 217.62ms | 0.22 | 4 | 216 |
| 5 ms | 64 | RoadRunner | **297** | 296–297 | 215.66ms | 219.34ms | 0.26 | 4 | 339 |
| 5 ms | 256 | Swerve (ext) | **5999** | 5949–5999 | 40.78ms | 64.78ms | 3.97 | 287 | 220 |
| 5 ms | 256 | Swerve (ext, built-in cache) | **5965** | 5919–5965 | 41.17ms | 67.45ms | 3.91 | 273 | 206 |
| 5 ms | 256 | Swerve (no ext) | **295** | 291–295 | 812.92ms | 1.01s | 0.20 | 4 | 128 |
| 5 ms | 256 | FrankenPHP | **293** | 293–293 | 854.74ms | 1.17s | 0.22 | 4 | 236 |
| 5 ms | 256 | RoadRunner | **290** | 290–290 | 862.98ms | 1.18s | 0.26 | 4 | 354 |

### Worker scaling, `/db/page`, c256, no added wait

| Workers | Server | req/s | p50 | p99 | CPU cores | MySQL conns | RSS MB |
|---:|---|---:|---:|---:|---:|---:|---:|
| 1 | Swerve (ext) | **1681** | 151.83ms | 185.30ms | 1.01 | 128 | 134 |
| 1 | Swerve (no ext) | **344** | 733.55ms | 986.12ms | 0.22 | 1 | 96 |
| 1 | FrankenPHP | **353** | 706.78ms | 896.97ms | 0.24 | 1 | 205 |
| 1 | RoadRunner | **332** | 757.15ms | 918.73ms | 0.24 | 1 | 181 |
| 2 | Swerve (ext) | **3179** | 80.06ms | 96.86ms | 2.01 | 256 | 183 |
| 2 | Swerve (no ext) | **704** | 363.95ms | 395.06ms | 0.45 | 2 | 105 |
| 2 | FrankenPHP | **727** | 351.64ms | 416.51ms | 0.48 | 2 | 215 |
| 2 | RoadRunner | **640** | 392.90ms | 433.22ms | 0.50 | 2 | 236 |
| 4 | Swerve (ext) | **6253** | 41.00ms | 54.52ms | 4.00 | 281 | 213 |
| 4 | Swerve (no ext) | **1388** | 188.40ms | 224.28ms | 0.90 | 4 | 117 |
| 4 | FrankenPHP | **1458** | 174.71ms | 189.25ms | 1.02 | 4 | 235 |
| 4 | RoadRunner | **1297** | 195.89ms | 211.28ms | 1.09 | 4 | 354 |
| 16 | FrankenPHP | **4225** | 60.49ms | 63.72ms | 3.11 | 16 | 327 |
| 16 | RoadRunner | **3333** | 76.74ms | 80.71ms | 3.31 | 16 | 1026 |
| 32 | FrankenPHP | **5417** | 47.17ms | 51.68ms | 3.79 | 32 | 317 |
| 32 | RoadRunner | **3624** | 70.42ms | 76.71ms | 3.88 | 32 | 1924 |

### Worker scaling, `/db/page`, c256, +5 ms

| Workers | Server | req/s | p50 | p99 | CPU cores | MySQL conns | RSS MB |
|---:|---|---:|---:|---:|---:|---:|---:|
| 1 | Swerve (ext) | **1625** | 157.03ms | 181.80ms | 1.00 | 128 | 132 |
| 1 | Swerve (no ext) | **43** | 5.02s | 9.64s | 0.04 | 1 | 96 |
| 1 | FrankenPHP | **42** | 5.06s | 9.64s | 0.04 | 1 | 216 |
| 1 | RoadRunner | **42** | 5.07s | 9.66s | 0.04 | 1 | 180 |
| 2 | Swerve (ext) | **3111** | 81.01ms | 113.97ms | 2.00 | 256 | 185 |
| 2 | Swerve (no ext) | **94** | 2.53s | 4.53s | 0.07 | 2 | 105 |
| 2 | FrankenPHP | **93** | 2.53s | 4.55s | 0.08 | 2 | 219 |
| 2 | RoadRunner | **93** | 2.55s | 4.58s | 0.09 | 2 | 235 |
| 4 | Swerve (ext) | **5606** | 44.82ms | 61.55ms | 3.93 | 271 | 207 |
| 4 | Swerve (no ext) | **195** | 1.29s | 2.05s | 0.14 | 4 | 128 |
| 4 | FrankenPHP | **196** | 1.26s | 1.99s | 0.16 | 4 | 235 |
| 4 | RoadRunner | **194** | 1.27s | 2.01s | 0.18 | 4 | 351 |
| 16 | FrankenPHP | **802** | 317.99ms | 325.31ms | 0.59 | 16 | 274 |
| 16 | RoadRunner | **795** | 321.03ms | 326.08ms | 0.77 | 16 | 1037 |
| 32 | FrankenPHP | **1593** | 160.68ms | 164.56ms | 1.17 | 32 | 449 |
| 32 | RoadRunner | **1559** | 164.05ms | 167.16ms | 1.69 | 32 | 1949 |
| 64 | FrankenPHP | **3152** | 80.90ms | 84.62ms | 2.35 | 64 | 513 |
| 64 | RoadRunner | **2656** | 96.11ms | 101.67ms | 3.74 | 64 | 3769 |
