#!/bin/bash
# HTTP hello-world benchmark: servers on this machine, wrk on another over ssh.
#
#   SERVER=192.168.10.4 CLIENT=loadgen ./run.sh "swerve-hello swerve-slim node-http node-express go" "64 1024 10000"
#
# SERVER    this machine's address as the client reaches it
# CLIENT    ssh host with wrk installed
# EXT       path to phasync-ext's .so for the swerve runs (empty: without the extension)
# WORKERS   processes for swerve and Node (default: one per CPU)
# GO_LAYOUT "1x56" (default: one process, all cores), or e.g. "2x28": that many processes of
#           that many threads, each pinned to a NUMA node with numactl
# Setup: `composer install` in swerve, `npm install` in node/, `go build -o hello .` in go/.
set -u
cd "$(dirname "$0")"
SERVER=${SERVER:?set SERVER} CLIENT=${CLIENT:?set CLIENT} EXT=${EXT:-}
WORKERS=${WORKERS:-$(nproc)} GO_LAYOUT=${GO_LAYOUT:-1x$(nproc)}
JIT="-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M"
PHP="php ${EXT:+-d extension=$EXT} $JIT ../bin/swerve.php --workers=$WORKERS -q --watchdog=0"
ulimit -n 1048576

start() { # $1 target, $2 port
    case $1 in
        swerve-hello) setsid $PHP --http=$SERVER:$2 ../tests/Fixtures/app.php ;;
        swerve-slim)  setsid $PHP --http=$SERVER:$2 ../swerve.php ;;
        node-http)    WORKERS=$WORKERS HOST=$SERVER setsid node node/http.js $2 ;;
        node-express) WORKERS=$WORKERS HOST=$SERVER setsid node node/express.js $2 ;;
        go)           local n=${GO_LAYOUT%x*} t=${GO_LAYOUT#*x}
                      for ((i = 0; i < n; i++)); do
                          if ((n > 1)); then
                              GOMAXPROCS=$t setsid numactl --cpunodebind=$((i % 2)) --membind=$((i % 2)) go/hello $SERVER:$2 &
                          else
                              GOMAXPROCS=$t setsid go/hello $SERVER:$2 &
                          fi
                      done; wait ;;
    esac
}
path() { [ "$1" = swerve-hello ] && echo /hello || echo /; }

port=18300
for target in $1; do
    ((port++))
    start "$target" $port > "/tmp/bench-$target.log" 2>&1 &
    sleep 5
    for c in $2; do
        out=$(ssh "$CLIENT" "ulimit -n 1048576; wrk -t32 -c$c -d10s --latency http://$SERVER:$port$(path "$target")")
        printf "%-14s c=%-6s %10s req/s  p50=%-9s p99=%-9s %s\n" "$target" "$c" \
            "$(awk '/Requests\/sec/{print $2}' <<< "$out")" "$(awk '$1=="50%"{print $2}' <<< "$out")" \
            "$(awk '$1=="99%"{print $2}' <<< "$out")" "$(grep -o 'Socket errors.*' <<< "$out")"
        sleep 2
    done
    # The whole process group each server started with setsid: a master, or cluster primary,
    # would restart workers signalled on their own
    for pid in $(ss -ltnp | grep ":$port " | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u); do
        pgid=$(ps -o pgid= -p "$pid" | tr -d ' ')
        [ -n "$pgid" ] && kill -TERM -- "-$pgid" 2> /dev/null # SIGINT: ignored by background jobs
    done
    sleep 3
done
