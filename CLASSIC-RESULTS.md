# Classic mode: results

Swerve classic forwards each request to a pool of `php -S` processes (`src/Classic/`), started
lazily and reaped 10 s after their last request. Benchmarked on black against php-fpm, serving
WordPress's front page.

## Method

- Servers (swerve classic, or nginx+php-fpm) pinned to cores 0-1 (`taskset`); `wrk` on cores 8-15.
- WordPress at `~/bench/wp/wordpress`, served at `http://127.0.0.1:18500/` (its configured
  siteurl); docroot and front controller (`index.php`) identical for every mode.
- CPU per request: ticks of every server process (swerve master+worker+every `php -S`, or
  nginx+fpm) from `/proc/*/stat`, summed over the measurement window and divided by request
  count. Cross-checked against ground truth — total busy ticks (user+system+irq+softirq) of
  cores 0-1 from `/proc/stat` — which read ~100% busy for every mode in the steady-state runs
  below, confirming both servers are genuinely CPU-bound on the two pinned cores and the
  per-process tick accounting is sound.
- Each run: 5 s unmeasured warm-up, then the real window; validated first (a pilot request
  checked for a real `200` of the expected ~69 KB page, not a redirect or error) and after every
  code change.
- `php -S`'s own request/response framing was verified directly against the real process (not
  assumed): it always answers `Connection: close` and closes after exactly one response,
  regardless of what the client asks; it accepts a `Transfer-Encoding: chunked` *request* body
  correctly; and its `flush()` does not reach the socket early unless `output_buffering=0` is
  set (confirmed byte-for-byte with a script that echoes, flushes, sleeps 0.2 s and echoes
  again — ships as a swerve test, `tests/ClassicProxyTest.php`).
- Port 18500 on black is shared with other agents' benchmarks; every step that uses it runs
  under `flock -o /tmp/bench-18500.lock <cmd>`, and each step stops exactly the processes it
  started, by PID.

## Steady state (c=32, pool=16, FPM children=16)

| server | req/s | CPU ms/req | cores 0-1 busy% | median latency | p99 latency | non-2xx |
|---|---|---|---|---|---|---|
| swerve classic (pre-connect **on**) | 95.3 | 21.0 | 100.3% | 328ms | 495ms | 0 |
| swerve classic (pre-connect **off**) | 95.7 | 20.9 | 100.2% | 327ms | 469ms | 0 |
| php-fpm `pm=static` 16 | 109.5 | 18.2 | 100.3% | 286ms | 425ms | 0 |
| php-fpm `pm=ondemand` 16 (FPM's own lazy mode) | 109.6 | 18.2 | 100.2% | 284ms | 448ms | 0 |

Classic reaches **87%** of FPM's throughput. Both are fully CPU-bound on the two pinned cores
(busy% ~100% throughout), and the gap is explained almost exactly by CPU cost: classic spends
~15% more CPU per request (21.0 vs 18.2 ms) than FPM, and 2000 ms / (ms per request) predicts the
observed req/s to within rounding for both (2000/21.0 ≈ 95, 2000/18.2 ≈ 110). Pre-connecting the
next backend socket ahead of time made no measurable difference here — see "what limits it",
below.

## Cold start (from zero processes)

- Sequential (c=1): the first request (a fresh process: fork+exec+opcache compile+WordPress's own
  bootstrap, including its DB connection) took **248 ms**; every one after it (the same process,
  reused) took **~35 ms**.
- Bursting at c=4 for the first 2 s from zero processes (`wrk -c4`, `--latency`): 119 requests,
  median **50 ms**, p75 **59 ms**, p90 **333 ms**, p99 **649 ms** — the slow tail is the handful
  of requests that each force a new process to start; once 4 processes exist (matching c=4) the
  rest are warm.

## Idle reaping (verify by process count)

A classic pool's process-group member count (master + worker + every `php -S`), started at 18
(2 + 16, after a c=8 burst): flat at 18 for the first 9 s after the last request, then down to 14
at t+10s, 13 at t+11s, and 2 (master+worker only, all 16 reaped) by t+12s — matching the 10 s idle
rule exactly, reaped by a 1 Hz sweep (not a timer per release — see below).

## The variants (opcache for a freshly-started `php -S`)

Measured as the true cold-start latency of a lazily-started process's very first request (waiting
for the TCP port to open, not for a full HTTP response, so the measurement isn't itself warmed by
the readiness probe) — this is where a warmer opcache should matter most, since by the time a
process is reused under steady load its own JIT/opcache state is already warm regardless of how
it got there.

| variant | first request |
|---|---|
| A: plain `php -S`, opcache on | ~255 ms |
| B: A + shared `opcache.file_cache=<dir>`, `file_cache_consistency_checks=0` | ~253 ms |
| C: B + `realpath_cache_size=16M`, `realpath_cache_ttl=600` | ~256 ms |

No measurable difference. `opcache.file_cache` exists to skip *compiling* the script in a fresh
process, but compiling WordPress's bootstrap is a small fraction of what a fresh request costs
here: process fork+exec, extension loading, and WordPress's own bootstrap (not least its MySQL
connection) dominate, and none of those are things an opcache variant touches. C's bigger
realpath cache made no difference either, for the same reason.

What *did* matter, found while measuring the variants rather than assumed: `php -S`'s own
`output_buffering` (on by default) holds a script's `flush()`ed output back until the script
ends, which would silently break any classic app relying on incremental output (a progress
indicator, an SSE-style long response). Shipped default now includes `output_buffering=0`
alongside the opcache settings (`src/Classic/ClassicIni.php`) — free (no measured cost) and
necessary for `flush()` to mean anything.

`SWERVE_CLASSIC_INI` (a JSON array of `-d` flags) and `SWERVE_CLASSIC_PRECONNECT=0` let a variant
be tried without editing the code; see `ClassicIni::flags()`/`::preconnect()`.

## What limits classic mode's speed

1. **`php -S` itself never keeps a connection.** Verified directly: it answers every response
   `Connection: close` and closes, regardless of what the client sends — so every proxied request
   pays a fresh TCP connect to its backend; there is no persistent-connection option to reach for.
   Pre-connecting the *next* socket right after a process starts or finishes a response (the
   prototype does this) hides that connect from the request that uses it — but it must be
   synchronous, not backgrounded: each HTTP connection runs its requests in a `Scope` of its own
   (`HttpConnection::serve()`) that joins every coroutine started inside it before reading the next
   request, so a backgrounded pre-connect would make the *client's* connection wait for it anyway,
   worse than not pre-connecting at all. Made synchronous, it costs a cheap loopback connect on
   this request's own path — and measured no difference either way at this workload's scale
   (steady state, pre-connect on vs off: 95.3 vs 95.7 req/s, within noise), because the backend's
   own compute (~20 ms, WordPress rendering) dwarfs a local connect.
2. **One swerve worker owns the whole pool.** Classic mode runs with exactly one worker by design
   (the prototype's one simplification: one pool, no cross-process sharing), so all the proxy's own
   work — rebuilding headers, parsing the backend's response, streaming the body — runs on one CPU
   core, competing with the backend processes for the same two pinned cores. A diagnostic (not
   shipped): splitting the same total pool across two independent swerve workers recovered real
   throughput and cut p99 latency by more than half under the same load, confirming the single
   worker as a genuine bottleneck, not just backend compute. Sharing one pool across several
   workers is real future work (cross-process coordination for the pool's slots), out of scope for
   this prototype.
3. **The proxy's own CPU cost is real and measured**: ~21.0 ms/request vs FPM's 18.2 ms, under
   the same fully-CPU-bound conditions (busy% ~100% for both) — a ~15% overhead for rebuilding the
   request, parsing the response head, and streaming the body, which directly explains classic's
   ~13% lower throughput at the same CPU budget.

## Correctness bugs found and fixed while measuring

Benchmarking surfaced two real defects, both now fixed and covered by tests
(`tests/ClassicProxyTest.php`):

- **A kept-alive client connection stalled ~10 s per request after the first.** The pool's
  idle-reap timer was one `phasync::go()` coroutine per `release()` — started *inside* the
  current request's `Scope`, which (as above) is joined before the connection reads its next
  request. Every request past the first on a reused connection waited out that 10 s timer before
  being read at all. Fixed: one sweep coroutine for the pool's whole life, started once at
  construction (before any connection exists, so it belongs to the worker's own frame), checking
  idle timestamps once a second; `release()` now only timestamps.
- **The whole pool could freeze permanently** when clients disconnect mid-response — the ordinary
  case at a benchmark's end, when `wrk` closes every connection: every busy process's exchange
  then waits on a now-dead client, and without a bound that wait never ends, pinning the process
  (and, with one worker and no other process left to serve a new connection, the whole pool)
  forever. Fixed with an explicit, bounded timeout (`ClassicProxy::IO_TIMEOUT`, 10 s) on every
  backend and client read/write; a stuck exchange now times out and the process is freed rather
  than held forever. (A related but separate finding, fixed alongside it: `$process->stop()` had
  been called for *any* failure, including a client that simply left — stopping a perfectly
  healthy backend process on every disconnect; it's now called only when the backend side itself
  broke.)

## Prototype scope

Classic mode here deliberately keeps to the task's one-worker, one-pool model: the whole document
root/front controller, pool, proxy and identity-passing (`auto_prepend_file`, strips any
client-sent copies of the headers it sets) are implemented (`src/Classic/`), with the request and
response bodies streamed rather than buffered in either direction. Sharing one pool across more
than one swerve worker, and the backend keep-alive question above, are the two concrete next steps
if classic mode grows past this prototype.
