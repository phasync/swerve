#!/bin/bash
# Runtime overhead: "Hello, World!" from the smallest server code on each runtime, over localhost
# on black. Server pinned to physical cores 0..N-1 (CCD0, no SMT siblings), wrk -t8 -c64 pinned
# to CCD1's cores 8-15. PHP with opcache and the tracing JIT.
#
#   ./run.sh dev|docs ["phasync phasync-ext node go"] ["1 2 8"]
#
# dev:  a 5 s warm-up, then one 10 s run (regression checks while developing)
# docs: a 5 s warm-up, then three 30 s runs; the best is reported (published numbers)
# Every run is appended to results.log. A setup already measured in the same mode (same key: the
# code, binaries and settings it depends on) is not run again; its logged result is reported.
# Setup on black, in $RT: phasync.so (phasync-ext), node-v26.10.0-linux-x64, go1.27.1; this
# swerve tree with its vendor/; `go build -o hello .` here.
set -u
cd "$(dirname "$0")"
MODE=$1 SERVERS=${2:-"phasync phasync-ext node go"} NS=${3:-"1 2 8"}
RT=$HOME/bench/rt PORT=18600 WRK="taskset -c 8-15 wrk -t8 -c64 --latency"
EXT=$RT/phasync.so NODE=$RT/node-v26.10.0-linux-x64/bin/node
PHP="php8.5 -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M"
SWERVE="../../bin/swerve.php -q --no-access-log --watchdog=0 --max-memory=0 --http=127.0.0.1:$PORT hello.php"
case $MODE in dev) RUNS=1 SECS=10 ;; docs) RUNS=3 SECS=30 ;; *) echo "mode: dev or docs"; exit 1 ;; esac
ulimit -n 1048576

# One benchmark at a time on black
exec 9> $HOME/bench/fpm-bench/bench.lock
flock -n 9 || { echo "black's bench lock is held by another benchmark"; exit 1; }

key() { # server: a hash of everything its result depends on
    { echo "$RUNS $SECS $WRK $PHP $SWERVE"; wrk -v 2>&1 | head -1; declare -f start
      case $1 in
          phasync*) echo "$PHP"; php8.5 -v; cat hello.php
                    (cd ../.. && find src bin vendor -type f -name '*.php' | sort | xargs cat)
                    [ "$1" = phasync-ext ] && cat $EXT ;;
          node)     $NODE -v; cat hello.js ;;
          go)       cat hello ;;
      esac; } | sha256sum | cut -c1-16
}

start() { # server n: on cores 0..n-1, as the leader of its own process group (exec: $! is its pid)
    local cpus=0-$(($2 - 1))
    case $1 in
        phasync)     exec setsid taskset -c $cpus $PHP $SWERVE --workers=$2 ;;
        phasync-ext) exec setsid taskset -c $cpus $PHP -d extension=$EXT $SWERVE --workers=$2 ;;
        node)        exec env WORKERS=$2 setsid taskset -c $cpus $NODE hello.js $PORT ;;
        go)          exec env GOMAXPROCS=$2 setsid taskset -c $cpus ./hello 127.0.0.1:$PORT ;;
    esac
}

stop() { # pid of the process group leader
    kill -TERM -- -$1 2> /dev/null
    for _ in $(seq 50); do ps -eo pgid= | grep -qx " *$1" || return; sleep 0.2; done
    kill -KILL -- -$1 2> /dev/null; sleep 1
}

declare -A BEST
for s in $SERVERS; do
    k=$(key $s)
    for n in $NS; do
        cached=$(awk -F'\t' -v m=$MODE -v s=$s -v n=$n -v k=$k '$2==m && $3==s && $4==n && $5==k {print $7, $9}' results.log 2> /dev/null)
        if [ -n "$cached" ]; then
            BEST[$s,$n]="$(sort -n <<< "$cached" | tail -1) (cached)"
            continue
        fi
        if ss -ltn | grep -q ":$PORT "; then echo "port $PORT is taken: stopping"; exit 1; fi
        start $s $n > /tmp/rt-$s.log 2>&1 < /dev/null &
        pid=$!
        for _ in $(seq 100); do [ "$(curl -s http://127.0.0.1:$PORT/)" = 'Hello, World!' ] && break; sleep 0.1; done
        if [ "$(curl -s http://127.0.0.1:$PORT/)" != 'Hello, World!' ]; then
            echo "$s N=$n did not start:"; tail -5 /tmp/rt-$s.log; stop $pid; continue
        fi
        $WRK -d5s http://127.0.0.1:$PORT/ > /dev/null
        for r in $(seq $RUNS); do
            out=$($WRK -d${SECS}s http://127.0.0.1:$PORT/)
            rps=$(awk '/Requests\/sec/{print $2}' <<< "$out")
            printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$(date -u +%FT%TZ)" $MODE $s $n $k $r $rps \
                "$(awk '$1=="50%"{print $2}' <<< "$out")" "$(awk '$1=="99%"{print $2}' <<< "$out")" \
                "$(grep -o 'Socket errors.*\|Non-2xx.*' <<< "$out" | tr '\n' ' ')" >> results.log
            echo "$s N=$n run $r: $rps req/s"
        done
        stop $pid
        if ss -ltn | grep -q ":$PORT "; then echo "$s N=$n left port $PORT taken: stopping"; exit 1; fi
        BEST[$s,$n]=$(awk -F'\t' -v m=$MODE -v s=$s -v n=$n -v k=$k '$2==m && $3==s && $4==n && $5==k {print $7, $9}' results.log | sort -n | tail -1)
    done
done

# The best run per server and N: req/s and its p99 latency
echo; printf '%-12s' "req/s, p99"; for n in $NS; do printf '%30s' "N=$n"; done; echo
for s in $SERVERS; do
    printf '%-12s' $s; for n in $NS; do printf '%30s' "${BEST[$s,$n]:-failed}"; done; echo
done
