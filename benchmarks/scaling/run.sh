#!/bin/bash
# The scaling matrix, run on the server (black): application × route × N ∈ PROCS × server ∈
# {PHP-FPM behind nginx with N children, swerve --workers=N, the same with phasync-ext}.
# wrk -t32 -c16N runs on the client (CLIENT, over ssh, with client.sh) against HOST.
# Opcache and tracing JIT everywhere. Raw output in raw/<app>-<route>-N<n>-<server>.txt.
#
#   ssh black 'bash ~/bench/scaling/run.sh [app...]'      (apps from setup.sh; default: all)
set -u
export PATH=~/bench/bin:$PATH
b=~/bench here=~/bench/scaling
HOST=${HOST:-192.168.10.15} PORT=${PORT:-19100} CLIENT=${CLIENT:-frode@192.168.10.4}
CDIR=${CDIR:-/home/frode/dev/swerve/benchmarks/scaling} # this directory on the client
PROCS=${PROCS:-4 16 32} SERVERS=${SERVERS:-fpm swerve swerve-ext} DURATION=${DURATION:-10}
EXT=$b/phasync.so
PHP=(php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M)
ulimit -n 1048576
mkdir -p "$here/raw"

# One benchmark at a time on this machine: the lock the adapters' benchmarks/run.sh take too
exec 9> $b/fpm-bench/bench.lock
flock -n 9 || { echo "another benchmark holds $b/fpm-bench/bench.lock" >&2; exit 1; }

app() { # sets dir, docroot, bin, script, opts, routes (name:path; the first is the readiness probe)
    local f=$b/swerve-$1/tests/Fixtures/app
    bin=vendor/bin/swerve script=swerve.php opts=() docroot=$f/public dir=$f
    case $1 in
    plain)       dir=$b/swerve docroot=$here/plain bin=bin/swerve.php script=tests/Fixtures/app.php
                 routes="json:/hello wait:/usleep?ms=10" ;;
    laravel)     opts=(--public=public); routes="json:/api/json session:/counter wait:/api/usleep?ms=10" ;;
    symfony)     routes="json:/json session:/counter wait:/usleep?ms=10" ;;
    yii)         routes="json:/json session:/ session-counter:/counter wait:/usleep?ms=10" ;;
    cakephp)     docroot=$f/webroot; routes="json:/bench/json session:/bench/session wait:/bench/usleep?ms=10" ;;
    spiral)      dir=$b/swerve-spiral/benchmarks/app docroot=$b/swerve-spiral/benchmarks/app/public
                 routes="json:/swerve/json session:/swerve/counter wait:/swerve/usleep?ms=10" ;;
    codeigniter) routes="json:/json session:/counter wait:/usleep?ms=10" ;;
    laminas)     opts=(--public=public); routes="json:/test/json session:/test/counter wait:/test/usleep?ms=10" ;;
    esac
}

start() { # server, n, log: starts it in a session of its own, sets pid
    case $1 in
    fpm) JIT=tracing BIND=$HOST setsid "$b/fpm-bench/serve.sh" "$docroot" $PORT "$2" > "$3" 2>&1 & pid=$! ;;
    swerve*)
        local ext=(); [ "$1" = swerve-ext ] && ext=(-d extension=$EXT)
        (cd "$dir" && exec setsid "${PHP[@]}" "${ext[@]}" $bin --workers="$2" --http=$HOST:$PORT --no-access-log -q "${opts[@]}" $script) > "$3" 2>&1 &
        pid=$! ;;
    esac
}

stop() { # stops $pid's session, then checks nothing of it or on the port is left
    kill -TERM "$pid"; wait "$pid" 2> /dev/null
    for _ in $(seq 50); do
        [ -z "$(ps -eo sid= | awk -v s=$pid '$1 == s')" ] && ! ss -ltn | grep -q ":$PORT " && return
        sleep 0.2
    done
    echo "LEFTOVER after stop: $(ps -eo pid,sid,comm,args | awk -v s=$pid '$2 == s')" | tee -a "$here/raw/problems.txt"
    kill -KILL -- "-$pid" 2> /dev/null; sleep 1
}

drops() { tc -s qdisc show dev eno1 | awk '/dropped/ { gsub(",", ""); print $7 }' | head -1; }

for a in ${*:-plain laravel symfony yii cakephp spiral codeigniter laminas}; do
    app $a
    probe=${routes%% *}; probe=${probe#*:}
    for n in $PROCS; do
        c=$((16 * n))
        for s in $SERVERS; do
            log=$here/raw/$a-N$n-$s.server.log
            start $s $n "$log"
            up=0
            for _ in $(seq 300); do curl -sf -o /dev/null "http://$HOST:$PORT$probe" && { up=1; break; }; sleep 0.1; done
            if [ $up = 0 ]; then echo "$a N=$n $s: not up" | tee -a "$here/raw/problems.txt"; stop; continue; fi
            # Sessions, made from the client as wrk will use them; and a check that one is kept
            cookies=/tmp/scaling-cookies.txt
            for r in $routes; do
                [[ ${r%%:*} == session* ]] || continue
                ssh $CLIENT "$CDIR/client.sh cookies 'http://$HOST:$PORT${r#*:}' 2048 $cookies"
                ssh $CLIENT "c=\$(head -1 $cookies); for i in 1 2; do curl -s -H 'User-Agent:' -H \"Cookie: \$c\" -D - 'http://$HOST:$PORT${r#*:}' | grep -ai 'set-cookie\|^HTTP' | tr -d '\r' | cut -c1-90; curl -s -H 'User-Agent:' -H \"Cookie: \$c\" 'http://$HOST:$PORT${r#*:}' | head -c 200; echo; done; echo sessions: \$(sort -u $cookies | grep -c .)" > "$here/raw/$a-N$n-$s.session-check.txt" 2>&1
                break # one set of sessions serves every session route of the app
            done
            for r in $routes; do
                name=${r%%:*} path=${r#*:} ck=""
                [[ $name == session* ]] && ck=$cookies
                out=$here/raw/$a-$name-N$n-$s.txt
                ssh $CLIENT "$CDIR/client.sh wrk $c 3 'http://$HOST:$PORT$path' $ck" > /dev/null # warm-up
                d0=$(drops); s0=$(cat /proc/stat)
                ssh $CLIENT "$CDIR/client.sh wrk $c $DURATION 'http://$HOST:$PORT$path' $ck" > "$out"
                printf '%s\n--\n%s\n' "$s0" "$(cat /proc/stat)" | awk -f "$here/cpu.awk" | sed 's/^/server: /' >> "$out"
                echo "qdisc_drops: $(( $(drops) - d0 ))" >> "$out"
                printf '%-12s %-8s N=%-3s %-11s %12s req/s  p99=%-9s %s | %s\n' $a $name $n $s \
                    "$(awk '/Requests\/sec/ {print $2}' "$out")" "$(awk '$1 == "99%" {print $2}' "$out")" \
                    "$(grep -h 'Socket errors\|Non-2xx' "$out" | tr '\n' ' ')" "$(grep -h '^server:\|^qdisc' "$out" | tr '\n' ' ')"
            done
            stop
            sleep 1
        done
    done
done
