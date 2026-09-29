# Set 2: the same framework applications across PHP application servers

Measured 2026-09-29 on black, over loopback, as set 1 (`../servers/SUMMARY.md`): servers pinned to
CCD0 (`taskset -c 0-7,16-23`, every process and thread; `srv.sh cpus` checked it for each start,
recorded in `raw/*-start.txt`), `wrk -t16` pinned to CCD1 against 127.0.0.1. N ∈ {2, 8} PHP
workers, each server started fresh per N, 3 s warm-up, 10 s measured with `--latency`, 2 rounds with
the servers interleaved; the tables show the median of the rounds. Every run held
`~/bench/fpm-bench/bench.lock`; every server was stopped by process group plus descendants and
nothing was left on the ports afterwards. Busiest client: 19 % of CCD1 (Slim on Swoole, 842k req/s).

- PHP 8.5.11, opcache + tracing JIT (128M) everywhere, loaded from ini directories
  (`PHP_INI_SCAN_DIR=:ini/<kind>`) so the processes Octane and RoadRunner spawn get them too;
  FrankenPHP (8.5.11 ZTS, the Docker image's build as in set 1) reads the same directory.
- swerve 0.1.0-alpha20 (Packagist, = the synced tag), phasync 2.0.0-alpha14; `swerve-ext` =
  phasync-ext 0.5.0-alpha13. Adapters at their current main, synced from /home/frode/dev.
- Every framework runs its adapter's test application (`tests/create-app.sh`) in production mode
  (as `../scaling/setup.sh`), with the server integrations added to the same application
  (`setup.sh`). Slim has no adapter: `apps/slim` is one application file plus one entry file per
  server.
- Routes (`routes/<fw>`): **json** the app's JSON route; **session** its session counter page, 2,048
  sessions made through the server under test just before, each wrk thread cycling 128 of its own
  (`session.lua`); **wait** `/usleep?ms=10` (a plain `usleep(10000)`) at 16 connections per worker
  and at 1,000 connections (**wait1k**, `--timeout 10s`).
- Cells: req/s / p99 / CPUs busy on CCD0 (of 16 hardware threads). `E` = socket errors or non-2xx
  in a run (only ever wrk timeouts, in wait1k, where queued requests outlive the 10 s timeout).

## Servers and their configuration

| Server | Integration and settings |
|---|---|
| swerve / swerve-ext | the adapter's `swerve.php`; `vendor/bin/swerve --workers=N -q --no-access-log` (`--public=public` for Laravel); ext = `extension=phasync.so` |
| RoadRunner 2025.1.15 | ext-protobuf in the workers. Laravel: **Octane 2.20** (`octane:start --server=roadrunner`; Octane's own rr options, incl. its `static` middleware and `warn`→`error` logs). Symfony: `runtime/roadrunner-symfony-nyholm` 1.0 (roadrunner-http 3.6). Slim: `PSR7Worker` + nyholm. Spiral: the skeleton's `app.php` via `spiral/roadrunner-bridge`. Yii: `yiisoft/yii-runner-roadrunner` 3.2 (`apps/yii/rr-worker.php`). Non-Octane cells: set 1's config (`relay: pipes`, `max_jobs: 0`, no access log, no HTTP middleware, error logs; RPC only for Spiral). |
| FrankenPHP 1.12.7 | Docker image build run natively (`frankenphp` wrapper), set 1's tuning: worker mode, `match *`, `file_server off`, `num_threads N+1`, `GODEBUG=cgocheck=0`, `admin off`. Laravel: Octane (`--server=frankenphp`) with `Caddyfile.octane` = Octane's stub with that tuning (no `encode`, no `try_files`). Symfony: **symfony/runtime 7.4's own `FrankenPhpWorkerRunner`** (runtime/frankenphp-symfony is merged into it), `FRANKENPHP_LOOP_MAX=0`. Slim: worker + nyholm `fromGlobals()`. CodeIgniter: its worker mode (`spark worker:install`'s `public/frankenphp-worker.php`); CodeIgniter needs ext-intl, which the image lacks: built with the image's own `install-php-extensions intl` (docker) and `intl.so` + the image's ICU 76 copied out (`franken-intl/`); the binary is the same (sha256). |
| Swoole 6.2.3 | SWOOLE_BASE, `worker_num N`, compression off, backlog 65535. Laravel: Octane (`--server=swoole`, `config/octane.php` `swoole.mode` = BASE, Octane's own options otherwise: coroutines off, `task_worker_num` = CPU count, idle). Symfony: `runtime/swoole` 1.0 (`apps/symfony/swoole.php` sets its options). Slim: set 1's bridge with `SWOOLE_HOOK_ALL` (so `usleep` yields). **swoole-process**: Octane's / runtime/swoole's default SWOOLE_PROCESS, measured as a second series (see below). |
| ReactPHP | Slim only: set 1's setup (N SO_REUSEPORT processes, ext-ev, `StreamingRequestMiddleware`, `/usleep` waits on a timer promise). |

Octane: `--max-requests=1000000000`. Its default (500) restarts every worker after 500 requests, a
~145 ms Laravel boot each time on RoadRunner, and `--max-requests=0` silently becomes 500
(`option() ?: config('octane.max_requests', 500)`); swerve and every other cell never restart.

## Skipped cells

- Spiral on FrankenPHP/Swoole, Yii on FrankenPHP/Swoole, CodeIgniter on RoadRunner/Swoole, and
  ReactPHP for everything but Slim: no official or well-established integration (the brief's
  matrix).
- Slim **session**: Slim has no session component; the common add-ons wrap PHP's native
  `session_*()`, which doesn't work in a long-running Swoole/ReactPHP/swerve worker. Writing one
  would benchmark our code, not an integration.
- Nothing was skipped for PHP 8.5: every integration installed and ran on it (runtime/swoole needed
  `--ignore-platform-req=ext-swoole` at install time only, since Composer runs without the extension).

## Results

### laravel

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 4,961 / 142.55 ms / 2.0 | 18,531 / 93.22 ms / 8.0 | 3.74× |
| swerve-ext | 4,929 / 7.72 ms / 2.0 | 18,431 / 10.19 ms / 8.0 | 3.74× |
| rr | 5,942 / 5.81 ms / 2.2 | 19,168 / 7.36 ms / 9.2 | 3.23× |
| franken | 6,235 / 5.58 ms / 2.1 | 21,533 / 6.42 ms / 9.1 | 3.45× |
| swoole | 5,669 / 10.64 ms / 2.0 | 18,934 / 24.04 ms / 7.0 | 3.34× |

#### session (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 1,406 / 96.18 ms / 1.3 | 5,830 / 120.62 ms / 5.4 | 4.15× |
| swerve-ext | 1,399 / 75.39 ms / 1.3 | 5,863 / 101.36 ms / 5.4 | 4.19× |
| rr | 1,452 / 34.03 ms / 1.3 | 5,418 / 42.23 ms / 5.3 | 3.73× |
| franken | 1,446 / 33.44 ms / 1.3 | 5,216 / 43.25 ms / 4.8 | 3.61× |
| swoole | 1,454 / 81.10 ms / 1.3 | 5,069 / 126.43 ms / 4.5 | 3.49× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 187 / 394.14 ms / 0.1 | 749 / 473.50 ms / 0.3 | 4.00× |
| swerve-ext | 188 / 255.19 ms / 0.1 | 754 / 268.94 ms / 0.3 | 4.01× |
| rr | 187 / 202.59 ms / 0.1 | 746 / 201.64 ms / 0.5 | 3.99× |
| franken | 187 / 233.66 ms / 0.1 | 751 / 200.99 ms / 0.4 | 4.01× |
| swoole | 95 / 337.91 ms / 0.1 | 427 / 743.24 ms / 0.2 | 4.50× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 182 / 9.70 s / 0.2 E | 659 / 4.79 s / 0.4 | 3.62× |
| swerve-ext | 88 / 9.95 s / 0.1 E | 661 / 2.81 s / 0.4 | 7.48× |
| rr | 92 / 9.95 s / 0.1 E | 662 / 2.42 s / 0.4 | 7.17× |
| franken | 93 / 9.95 s / 0.1 E | 665 / 2.40 s / 0.4 | 7.14× |
| swoole | 93 / 9.72 s / 0.1 | 439 / 5.80 s / 0.2 | 4.72× |

### symfony

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 25,988 / 2.48 ms / 2.0 | 94,564 / 2.62 ms / 8.0 | 3.64× |
| swerve-ext | 26,311 / 2.42 ms / 2.0 | 96,622 / 2.66 ms / 8.0 | 3.67× |
| rr | 27,245 / 1.92 ms / 2.1 | 57,607 / 2.79 ms / 8.9 | 2.11× |
| franken | 35,958 / 1.31 ms / 2.6 | 88,949 / 1.90 ms / 10.8 | 2.47× |
| swoole | 56,180 / 1.25 ms / 2.0 | 206,247 / 1.43 ms / 8.0 | 3.67× |

#### session (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 4,043 / 86.06 ms / 1.1 | 8,564 / 131.29 ms / 2.7 | 2.12× |
| swerve-ext | 4,099 / 84.59 ms / 1.1 | 8,637 / 111.00 ms / 2.7 | 2.11× |
| rr | 4,198 / 62.66 ms / 1.2 | 6,918 / 50.91 ms / 2.3 | 1.65× |
| franken | 4,302 / 62.25 ms / 1.2 | 7,073 / 50.14 ms / 2.3 | 1.64× |
| swoole | 1,567 / 95.88 ms / 0.3 | 7,709 / 119.43 ms / 1.8 | 4.92× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 194 / 289.93 ms / 0.0 | 780 / 325.76 ms / 0.1 | 4.02× |
| swerve-ext | 2,611 / 21.98 ms / 0.2 | 10,455 / 22.27 ms / 0.7 | 4.00× |
| rr | 194 / 165.17 ms / 0.0 | 773 / 192.36 ms / 0.2 | 3.98× |
| franken | 194 / 225.72 ms / 0.0 | 782 / 163.15 ms / 0.1 | 4.04× |
| swoole | 99 / 476.31 ms / 0.0 | 345 / 996.23 ms / 0.0 | 3.50× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 106 / 9.08 s / 0.0 | 692 / 2.42 s / 0.1 | 6.51× |
| swerve-ext | 2,813 / 473.30 ms / 0.2 | 11,497 / 100.94 ms / 0.7 | 4.09× |
| rr | 100 / 9.94 s / 0.0 | 692 / 2.33 s / 0.1 | 6.91× |
| franken | 101 / 9.90 s / 0.0 | 696 / 2.31 s / 0.1 | 6.92× |
| swoole | 100 / 9.39 s / 0.0 | 489 / 8.49 s / 0.1 | 4.91× |

### slim

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 180,803 / 0.19 ms / 2.0 | 705,481 / 0.24 ms / 8.0 | 3.90× |
| swerve-ext | 178,434 / 0.36 ms / 2.0 | 698,720 / 0.39 ms / 8.0 | 3.92× |
| rr | 74,992 / 0.80 ms / 1.9 | 132,040 / 1.45 ms / 10.1 | 1.76× |
| franken | 188,265 / 0.32 ms / 5.5 | 365,643 / 1.10 ms / 13.8 | 1.94× |
| swoole | 214,208 / 0.33 ms / 2.0 | 834,311 / 0.32 ms / 8.0 | 3.89× |
| react | 140,408 / 0.28 ms / 2.0 | 545,602 / 0.36 ms / 8.0 | 3.89× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 3,141 / 10.24 ms / 0.1 | 12,572 / 10.25 ms / 0.2 | 4.00× |
| swerve-ext | 3,103 / 10.39 ms / 0.1 | 12,363 / 10.45 ms / 0.2 | 3.98× |
| rr | 197 / 161.98 ms / 0.0 | 787 / 161.97 ms / 0.0 | 4.00× |
| franken | 197 / 161.26 ms / 0.0 | 785 / 191.60 ms / 0.0 | 3.98× |
| swoole | 3,151 / 10.53 ms / 0.0 | 12,574 / 10.61 ms / 0.2 | 3.99× |
| react | 3,054 / 10.56 ms / 0.1 | 12,179 / 10.75 ms / 0.3 | 3.99× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 96,322 / 10.91 ms / 1.3 | 96,729 / 10.30 ms / 1.4 | 1.00× |
| swerve-ext | 81,778 / 13.61 ms / 1.2 | 86,198 / 12.11 ms / 1.2 | 1.05× |
| rr | 103 / 9.82 s / 0.0 | 702 / 2.29 s / 0.0 | 6.85× |
| franken | 102 / 9.80 s / 0.0 | 700 / 2.29 s / 0.0 | 6.87× |
| swoole | 88,809 / 12.73 ms / 1.0 | 92,596 / 12.14 ms / 1.1 | 1.04× |
| react | 69,361 / 16.34 ms / 1.5 | 81,803 / 12.92 ms / 1.8 | 1.18× |

### spiral

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 5,346 / 6.34 ms / 2.0 | 19,754 / 8.93 ms / 8.0 | 3.70× |
| swerve-ext | 5,332 / 7.31 ms / 2.0 | 19,800 / 9.16 ms / 8.0 | 3.71× |
| rr | 7,326 / 5.06 ms / 2.2 | 21,353 / 6.83 ms / 8.8 | 2.91× |

#### session (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 3,991 / 8.94 ms / 2.0 | 14,752 / 12.18 ms / 8.0 | 3.70× |
| swerve-ext | 3,833 / 10.27 ms / 2.0 | 14,612 / 11.70 ms / 8.0 | 3.81× |
| rr | 5,116 / 7.05 ms / 2.2 | 16,044 / 8.94 ms / 8.8 | 3.14× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 190 / 194.59 ms / 0.1 | 758 / 215.24 ms / 0.3 | 4.00× |
| swerve-ext | 189 / 205.81 ms / 0.1 | 752 / 253.44 ms / 0.3 | 3.98× |
| rr | 190 / 168.01 ms / 0.1 | 756 / 169.03 ms / 0.4 | 3.98× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 94 / 9.95 s / 0.1 E | 669 / 2.42 s / 0.3 | 7.14× |
| swerve-ext | 88 / 9.96 s / 0.2 E | 660 / 2.52 s / 0.4 | 7.51× |
| rr | 95 / 9.95 s / 0.1 E | 668 / 2.40 s / 0.4 | 7.04× |

### yii

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 51,757 / 0.71 ms / 2.0 | 196,881 / 0.84 ms / 8.0 | 3.80× |
| swerve-ext | 50,822 / 1.19 ms / 2.0 | 192,392 / 1.29 ms / 8.0 | 3.79× |
| rr | 37,023 / 1.40 ms / 2.5 | 79,961 / 2.19 ms / 10.6 | 2.16× |

#### session (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 38,280 / 1.07 ms / 2.0 | 142,492 / 1.21 ms / 8.0 | 3.72× |
| swerve-ext | 37,753 / 1.30 ms / 2.0 | 142,613 / 1.60 ms / 8.0 | 3.78× |
| rr | 28,189 / 1.73 ms / 2.5 | 59,122 / 2.76 ms / 10.3 | 2.10× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 196 / 192.54 ms / 0.0 | 777 / 232.73 ms / 0.1 | 3.96× |
| swerve-ext | 196 / 177.95 ms / 0.0 | 780 / 218.10 ms / 0.1 | 3.98× |
| rr | 196 / 163.06 ms / 0.0 | 776 / 192.34 ms / 0.1 | 3.97× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 101 / 9.88 s / 0.0 E | 698 / 2.38 s / 0.1 | 6.92× |
| swerve-ext | 95 / 9.93 s / 0.1 E | 689 / 2.56 s / 0.1 | 7.27× |
| rr | 101 / 9.90 s / 0.0 | 696 / 2.33 s / 0.1 | 6.90× |

### codeigniter

#### json (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 5,432 / 6.89 ms / 2.0 | 20,619 / 8.67 ms / 8.0 | 3.80× |
| swerve-ext | 5,336 / 7.34 ms / 2.0 | 20,181 / 9.06 ms / 8.0 | 3.78× |
| franken | 5,091 / 6.77 ms / 2.1 | 17,603 / 7.96 ms / 8.9 | 3.46× |

#### session (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 4,195 / 8.54 ms / 2.0 | 16,057 / 10.88 ms / 8.0 | 3.83× |
| swerve-ext | 4,128 / 8.91 ms / 2.0 | 15,681 / 11.84 ms / 8.0 | 3.80× |
| franken | 3,086 / 22.95 ms / 2.1 | 10,028 / 29.84 ms / 8.6 | 3.25× |

#### wait (16 connections per worker)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 187 / 239.98 ms / 0.1 | 751 / 220.02 ms / 0.4 | 4.02× |
| swerve-ext | 187 / 191.03 ms / 0.1 | 747 / 233.41 ms / 0.4 | 3.99× |
| franken | 188 / 170.23 ms / 0.1 | 749 / 171.13 ms / 0.4 | 3.99× |

#### wait1k (1,000 connections)

| Server | N=2: req/s / p99 / CPUs | N=8: req/s / p99 / CPUs | N=8 ÷ N=2 |
|---|---:|---:|---:|
| swerve | 89 / 9.95 s / 0.1 E | 662 / 2.51 s / 0.4 | 7.45× |
| swerve-ext | 76 / 9.96 s / 0.3 E | 649 / 2.54 s / 0.5 | 8.58× |
| franken | 92 / 9.95 s / 0.1 E | 659 / 2.46 s / 0.4 | 7.20× |

### Swoole in its default SWOOLE_PROCESS mode (Octane, runtime/swoole)

| Framework / route | N=2 | N=8 | 8 ÷ 2 |
|---|---:|---:|---:|
| Laravel json | 5,789 / 5.59 ms / 2.0 | 21,126 / 6.30 ms / 8.3 | 3.65× |
| Laravel session | 1,447 / 78.63 ms / 1.3 | 5,572 / 98.58 ms / 5.0 | 3.85× |
| Laravel wait | 189 / 232.38 ms / 0.1 | 754 / 322.17 ms / 0.4 | 4.00× |
| Laravel wait1k | 93 / 9.96 s / 0.1 E | 668 / 2.40 s / 0.4 | 7.20× |
| Symfony json | 65,208 / 1.08 ms / 2.0 | 170,636 / 1.48 ms / 10.0 | 2.62× |
| Symfony session | 5,747 / 85.14 ms / 1.2 | 7,931 / 110.34 ms / 1.9 | 1.38× |
| Symfony wait | 195 / 284.21 ms / 0.0 | 783 / 319.44 ms / 0.1 | 4.02× |
| Symfony wait1k | 101 / 9.85 s / 0.0 | 698 / 2.29 s / 0.1 | 6.89× |

## What the numbers say

- **One request at a time per worker** (every Laravel, Spiral, Yii and CodeIgniter cell, Symfony
  except swerve-ext): `wait` is N ÷ 10 ms (≈190 and ≈760–790) on every server, as expected, and at
  1,000 connections the requests queue for seconds (wait1k ≈ half of N ÷ 10 ms at N=2, because the
  warm-up's abandoned requests are served first, as in set 1). Only **swerve-ext with Symfony**
  (kernel pool, `usleep` yields) overlaps the waits: 2,611 / 10,455 req/s at 16 per worker and
  2,813 / 11,497 at 1,000 connections, p99 22 ms / 101 ms. Slim overlaps on swerve (both),
  Swoole and ReactPHP: ≈3,150 / 12,570 at 16 per worker (the client's limit) and 70k–97k at 1,000
  connections (the route's limit is 100k: 1,000 connections ÷ 10 ms).
- **json, framework cost dominates**: Laravel 5–6k req/s per 2 workers everywhere, Spiral and
  CodeIgniter 5–7k. The framework's own request cost (0.15–0.4 ms) hides the server; the server
  matters for Slim, Yii and Symfony.
- **8 ÷ 2 on json**: swerve 3.6–3.9× everywhere (all work in the N PHP processes, N CPUs). RoadRunner
  and FrankenPHP use CPUs beyond N for their Go side (FrankenPHP Slim at N=2: 5.5 CPUs), so their
  ratio is not a ratio of CPUs (1.8–2.5× on the light apps, ~3.2–3.5× on the heavy ones where the Go
  side is a small share). Swoole (BASE) and ReactPHP scale like swerve.
- **Where swerve is behind**: Octane is ahead of swerve-laravel on json, 20–26 % at N=2
  (FrankenPHP 6,235, RoadRunner 5,942 vs 4,961) and 3–16 % at N=8; Spiral on RoadRunner is 37 % ahead at N=2 (8 % at N=8);
  Symfony on runtime/swoole does 2.2× swerve-symfony (56,180 vs 25,988 at N=2, 206k vs 95k at
  N=8) and FrankenPHP 1.4× at N=2. For Symfony it is the adapter: a CLI micro-benchmark
  (`apps/symfony/micro.php`, one CPU) has the kernel's handle+terminate at 23 µs and
  `Swerve\Symfony\Handler::handle()` at 44–45 µs; `toSymfony()` is 1.7 µs and the `phasync::go()`
  for terminate ~4 µs in a plain loop. Follow-up (swerve-symfony#1): that `phasync::go()` is the
  cost. It is the only fiber swerve-symfony creates per request, and in a process as large as a
  Symfony app a new fiber costs ~5.5 µs itself (PHP maps a fresh 2 MiB stack for each) and slows the
  next request by 2–5 µs more. Running terminate inline instead: 25.8k → 37.4k req/s at N=2, 94.5k →
  135.0k at N=8 (+43 %). The pool size is not it (`KERNELS=1`: same req/s).
- **Where swerve is ahead**: Slim on swerve 181k / 705k vs RoadRunner 75k / 132k and FrankenPHP 188k /
  366k (FrankenPHP's N=2 figure uses 5.5 CPUs); Swoole is 18 % ahead of swerve on Slim, as in
  set 1. Yii: swerve 52k / 197k json and 38k / 142k session vs RoadRunner 37k / 80k and 28k / 59k.
  CodeIgniter: swerve 7 % / 17 % ahead of FrankenPHP on json (N=2 / N=8), 36 % / 60 % on session.
- **session** is bound by each app's session store, not the server: Laravel and Symfony keep
  sessions in SQLite (CPUs 1.1–5.4 of N; Symfony 2.1× from N=2 to N=8), Spiral, Yii and
  CodeIgniter in files.

## Things that looked wrong, and how they were checked

- **Yii on RoadRunner, session: not comparable — a session leak.** The 2,048 cookie-less requests
  that made the sessions got only 2 distinct session ids at N=2 and 8 at N=8 (one per worker;
  `raw/*-yii-rr-session-check.txt`). yiisoft/session 3.0.2 starts the session with whatever
  `session_id()` PHP still holds, and yii-runner-roadrunner 3.2.0 doesn't clear it between requests,
  so a visitor without a cookie gets the previous visitor's session. swerve-yii clears it
  (`session_id(''); $_SESSION = []` after each request, `src/`). The Yii/RoadRunner session numbers
  are for few shared sessions; not fixed here, worth reporting upstream.
- **Octane restarts workers every 500 requests**, even with `--max-requests=0` (above). Found as
  periodic 145 ms stalls every ~530 requests on RoadRunner (a sequential probe, `raw-aborted/` has
  the first run with it: RoadRunner json at half of swerve's). Fixed with 10⁹; afterwards no
  request over 10 ms in 20,000 sequential ones on Octane RoadRunner, Swoole and FrankenPHP, and
  Symfony on FrankenPHP and Swoole.
- **Octane on Swoole with SWOOLE_BASE dropped connections** (read errors, ~200 req/s) in the first
  run: the worker restarts above close a BASE worker's connections. With no restarts it works.
- **Swoole in SWOOLE_BASE halves `wait` for blocking frameworks** (Laravel 95 / 427, Symfony 99 /
  345 vs ~190 / ~780; Symfony session at N=2 1,567 vs 4,000–4,300; up to 60 % between rounds). In
  BASE mode a worker that is blocked (serving the warm-up's queued requests) doesn't accept, so the
  next wrk's connections all land on the few free workers and the others sit idle for the whole run.
  Counted with `ss -tnp` on a fresh start: 46/21/21/19/9/6/5/1 connections over 8 workers (and all
  128 on the master in PROCESS mode). SWOOLE_PROCESS gives the full N ÷ 10 ms (table above) and the
  better p99 on Laravel json; BASE stays ahead on Symfony json at N=8 (206k vs 171k, the latter
  with 10 CPUs). For blocking frameworks PROCESS, Octane's default, is the right setting.
- **swerve without phasync-ext has a long tail on Laravel json** (p99 142 ms at N=2 vs 7.7 ms with
  the extension; same throughput). Follow-up (phasync#53): not the poller. swerve-laravel serves
  requests one at a time through phasync's `Synchronized`, whose release let whichever coroutine
  ran first take the lock, often a newly arrived request, so a waiting one could lose many times in
  a row (handler times up to 518 ms against 0.4 ms). The poller only changed who won. phasync
  2.0.0-alpha15 hands the lock to the longest waiter: N=1, 16 connections, p99 149.9 ms → 6.6 ms
  (6.5 ms with phasync-ext), same throughput. The tables above are from before the fix.
- **CodeIgniter session on FrankenPHP varied 16–21 % between rounds**; the others were within 4 %
  (listed by `summarize.py`). Not investigated further.
- Symfony on FrankenPHP: `FrankenPhpWorkerRunner` calls `gc_collect_cycles()` after every request
  (its design, not changed). Its default `worker_loop_max` 500 was lifted with `FRANKENPHP_LOOP_MAX=0`.
- JIT reaching FrankenPHP through `PHP_INI_SCAN_DIR` was checked (`opcache.jit` = tracing, intl
  loaded); phasync-ext being active shows in Symfony's overlapping waits; ext-protobuf was loaded in
  Octane's RoadRunner workers (`/proc/<pid>/maps`).
- Session routes: every other cell had 2,048 distinct sessions and a counter that goes up with
  the cookie (`raw/*-session-check.txt`).
- black is a desktop: Chrome and TeamViewer were running (a few % of a CPU, not pinned). No run had
  round-to-round differences above 4 % except the Swoole BASE and CodeIgniter/FrankenPHP session
  cells above.

## Files

- `setup.sh`: how the applications were built on black (ini directories, the FrankenPHP wrapper
  and intl, each framework's app and integrations). `apps/`: the Slim app, Symfony's Swoole front
  controller, Yii's RoadRunner worker, the Symfony micro-benchmark. `Caddyfile.octane`, `routes/`.
- `srv.sh` (black): starts FW × SERVER × N pinned; `stop`, `cpus`. `bench.sh` (black): the runs.
  `summarize.py raw`: the tables.
- `raw/`: every run (`r<round>-N<n>-<fw>-<server>-<route>.txt`, `-start.txt`, `-session-check.txt`,
  `-server.log`), `run.log`, `run-process.log`. `raw-aborted/`: the first, stopped run (Octane
  restarting every 500 requests).

Rerun on black: `bash ~/bench/fw/bench.sh ~/bench/fw/raw "<fw>:<server> …" "2 8" 2`.
