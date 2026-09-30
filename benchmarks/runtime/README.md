# Runtime overhead: "Hello, World!"

The smallest server code on each runtime, answering every request with `Hello, World!`: swerve
with a bare PSR-15 handler (`hello.php`, no adapter, no access log, watchdog and memory recycling
off), with and without phasync-ext; Node's `http` module in cluster mode (`hello.js`); Go's
`net/http` (`main.go`, one process, `GOMAXPROCS=N`).

Measured on black (Ryzen 9 9950X3D) over localhost: the server pinned to physical cores 0..N-1,
wrk `-t8 -c64` pinned to cores 8-15 (the other chiplet). N is 1, 2 and 4: at 8, wrk's 8 threads were the limit (every server scaled only 3.3-3.5× from N=2). PHP 8.5 with opcache and the tracing JIT.
`run.sh dev` is a 5 s warm-up and one 10 s run per server and N; `run.sh docs` is a warm-up and
three 30 s runs, reporting the best. Every run is appended to `results.log`, and a setup whose
code, binaries and settings haven't changed is not run again. See the top of `run.sh`.
