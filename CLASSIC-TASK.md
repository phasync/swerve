# Task: Swerve classic mode on a php -S pool, benchmarked against PHP-FPM

Branch `classic` of swerve, worktree `~/dev/swerve-classic`. Local only: never push.

## The model (owner's decisions)

- **Classic mode**: Swerve forwards each classic request over HTTP to a `php -S` process, which runs
  the application's front controller with PHP's own per-request lifecycle (fresh statics/globals,
  native header()/echo/session/exit/uploads). No ext-phasync, no plug, no babel involved.
- **The pool starts empty and grows on demand**: a new `php -S` process is started only when a
  classic request finds no idle one, up to a configurable maximum (requests beyond that queue).
  **No pre-forking, no pre-started processes.** A process exits after 10 s without a request.
- Swerve's own workers handle only worker-mode requests (concurrent or serialized coroutines) and
  the classic proxying itself.

## Prototype

Minimal and measurable, not a finished feature: a way to start swerve in classic mode with a
document root and front controller (as `php -S docroot router.php` would), the pool, and the proxy.
- Each `php -S` listens on its own 127.0.0.1 port; one request at a time per process.
- Forward method, URI, headers and body (de-chunk request bodies; send Content-Length); stream the
  response back as it arrives.
- Pass the client's identity through: an `auto_prepend_file` given to each `php -S` sets
  `REMOTE_ADDR`, `HTTPS`, `SERVER_PORT` and the host from headers only Swerve sets (strip any
  client-sent copies of them).
- Static files are served by Swerve directly (`--public`), never reaching php -S.

## Find the fastest variant, then benchmark it against FPM

Variants of how a php -S process is started and warmed, all lazily (no pre-start):
- A: plain `php -S`, opcache on (each process compiles the app itself on its first requests);
- B: A plus `opcache.file_cache=<dir>` shared by all processes (a new process loads compiled
  scripts from disk);
- C: anything else you find that keeps processes lazy but makes them start faster or warmer
  (e.g. `opcache.file_cache_only`, `opcache.file_cache_consistency_checks=0`, `realpath_cache`
  settings), each measured, not assumed.

Benchmark on black (ssh black; see `~/bench/ext-baseline.sh` there for the established method:
servers pinned to cores 0-1, wrk on cores 8-15, CPU per request from the server processes'
ticks; WordPress at `~/bench/wp/wordpress`, siteurl http://127.0.0.1:18500, nginx + php-fpm via
`~/bench/wp/fpm-serve.sh`). Measure, for WordPress's front page:
1. **Steady state**, c=32: req/s, CPU ms per request (all server processes: swerve, every php -S,
   or nginx + fpm), median and p99 latency. Swerve classic with the pool limit equal to FPM's
   children count (16), and FPM with pm=static 16 and pm=ondemand 16 (ondemand is FPM's lazy mode,
   the fair comparison for "no pre-start").
2. **Cold start**: from zero processes, the latency of the first 20 requests at c=4.
3. **Idle reaping**: processes exit 10 s after their last request (verify by process count).

Rules: validate before long runs (a short pilot of each mode, check every response is a real 200
page of the right size, not a redirect or error); stop every process by PID after each run; no
other benchmarks. Write `CLASSIC-RESULTS.md` (the table, the method, the variant comparison, and
what limits classic mode's speed), commit on the `classic` branch (messages end with
`Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`).
