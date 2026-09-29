# Set 2: the same framework applications across servers

Goal: for each framework, the same application (same routes, same code) on every server that has
an official or well-established integration for it, measured the same way as set 1.

## Method (as set 1, see benchmarks/servers/SUMMARY.md)

- Everything on black over loopback. Servers pinned to CCD0 (`taskset -a -c 0-7,16-23`, the
  whole process group, Go runtimes included), wrk pinned to CCD1 (`taskset -c 8-15,24-31`,
  `wrk -t16`). No runs over the network.
- Worker counts N ∈ {2, 8}; report the 8/2 ratio for every cell.
- Warm-up 3 s, measure 10 s, 2 interleaved rounds, `--latency`. Record req/s, p50, p99,
  errors/non-2xx.
- PHP 8.5.11 (`~/bench/bin/php`), opcache + tracing JIT everywhere (on php-fpm's or a Go
  server's command line where a pool config ignores it), debug off, production environment.
- swerve and swerve + phasync-ext (0.5.0-alpha13) are separate series.
- Each server gets its documented best settings for throughput; say what was tuned.
- Stop servers by process group; never `pkill -f` patterns.

## Matrix

| Framework | RoadRunner | FrankenPHP | Swoole | ReactPHP | swerve |
|---|---|---|---|---|---|
| Laravel 13 | Octane (roadrunner) | Octane (frankenphp) | Octane (swoole) | – | swerve-laravel |
| Symfony 7.4 | Symfony Runtime (runtime/roadrunner-symfony-nyholm) | runtime/frankenphp-symfony | runtime/swoole | – | swerve-symfony |
| Slim 4 (PSR-15) | PSR7Worker + nyholm | worker mode + nyholm fromGlobals | Swoole→PSR-7 converter from set 1 | react/http | swerve |
| Spiral 3 | spiral/roadrunner-bridge | – | – | – | swerve-spiral |
| Yii 3 | yii-runner-roadrunner | – | – | – | swerve-yii |
| CodeIgniter 4.7 | – | its worker mode | – | – | swerve-codeigniter |

Use the adapters' test applications (each adapter's `tests/create-app.sh` builds it) as the
application, adding the server integration package to the same app, so the code under test is
identical across servers. Skip a cell whose integration doesn't support PHP 8.5 or the framework
version, and say why.

## Routes

1. JSON: the framework's JSON response.
2. Session page: a page that reads and writes the session (each app's existing session route;
   wrk with a lua script cycling warmed session cookies, as the scaling benchmark did).
3. Wait 10 ms (`/usleep` or equivalent): each server's normal behaviour. Only swerve-symfony
   (kernel pool) and Slim on swerve/Swoole/ReactPHP can overlap waits; the others serve one
   request at a time per worker by design. Run it at 16 connections per worker and at 1,000.

## Output

benchmarks/frameworks/ with scripts, app setup, raw output and SUMMARY.md: tables per framework
(server × N × route, req/s with p99, 8/2 ratio), configuration notes per server, anything that
looked wrong and how it was ruled out. Don't commit; report back.
