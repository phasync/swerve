# HTTP benchmark: swerve, Node and Go

Hello world over HTTP/1.1 with keep-alive: the same response ("Hello", or "Hello, World" for
Express and Slim) from swerve, Node's http module, Express and Go's net/http, measured with wrk
from a second machine. Each server gets its fastest worker or thread count, found by trying
several: a default that suits one server can cost another half its throughput.

| Connections | Node http | Go net/http | swerve | Node + Express | swerve + Slim |
|---|---:|---:|---:|---:|---:|
| 64 | 246,024 (16 w) | 196,816 (2×8) | 235,568 (16 w, ext) | 67,446 (56 w) | 212,235 (16 w, ext) |
| 1,024 | 216,529 (16 w) | 317,434 (1×16) | 284,562 (16 w) | 92,365 (56 w) | 248,132 (16 w) |
| 10,000 | 163,444 (8 w) | 183,374 (1×16) | 210,986 (16 w) | 85,381 (56 w) | 194,926 (16 w) |
| ~28,000 | 135,011 (16 w) | 162,497 (1×16) | 157,152 (16 w, ext) | 73,971 (56 w) | 160,221 (16 w, ext) |

Requests per second, one 10 s run each; "16 w" is 16 worker processes, "2×8" two Go processes
of 8 threads each on separate NUMA nodes, "ext" swerve with phasync-ext. Every run, including
latency percentiles, timeouts and the slower layouts, is in
[results/2026-09-28-http.txt](results/2026-09-28-http.txt).

## Setup

- Server: 2 × Xeon E5-2697 v3 (28 cores, 56 hardware threads, 2 NUMA nodes), Linux 6.8, 1 GbE.
  Client: a second machine with 32 cores running wrk 4.1 with 32 threads.
- PHP 8.5.11 with opcache and the tracing JIT, phasync 2.0.0-alpha11, phasync-ext 0.5.0-alpha7;
  Node 22.19 (cluster module); Go 1.22.2.
- Both machines use the fq queueing discipline (limit 200,000 packets, 10,000 per connection) and
  4,096-entry NIC rings. The default fq_codel dropped packets under many connections and
  distorted every server's results.
- At 50,000 requested connections the client held about 28,000: one client address has only
  that many ports for one server port. Every server shows the same connect errors there.

## Running it

```bash
composer install                      # in swerve
(cd benchmarks/node && npm install)
(cd benchmarks/go && go build -o hello .)
SERVER=192.168.10.4 CLIENT=loadgen WORKERS=16 EXT=/path/to/phasync.so \
    benchmarks/run.sh "swerve-hello swerve-slim node-http node-express go" "64 1024 10000 50000"
```

`CLIENT` is an ssh host with wrk; `GO_LAYOUT=2x8` runs Go as two NUMA-pinned processes of 8
threads. See the top of [run.sh](run.sh).
