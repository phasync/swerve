#!/bin/bash
# bench.sh OUT "FW:SERVER ..." "NS" [ROUNDS] [ROUTES]: runs on black (everything over loopback).
# Servers pinned to CCD0 (taskset -a semantics: started under taskset, every process and thread
# inherits it; `srv.sh cpus` records it), wrk -t16 pinned to CCD1 against 127.0.0.1:18500.
# For each round, N and FW:SERVER (interleaved within a round), the server is started fresh; per
# route a 3 s warm-up and a 10 s measurement, wrk --latency, /proc/stat sampled 1 s into the run
# and 8 s later. Routes (from routes/<fw>): json and session at -c16N, wait at -c16N and at
# -c1000 (wait1k, --timeout 10s). The session route cycles 2048 sessions made through the server
# under test just before (session.lua: each wrk thread its own 128). The whole run holds
# ~/bench/fpm-bench/bench.lock, the lock every benchmark on black takes.
# Raw output: OUT/r<round>-N<n>-<fw>-<server>-<route>.txt, ...-start.txt, ...-session-check.txt
set -u
OUT=$1 CELLS=$2 NS=$3 ROUNDS=${4:-2} ROUTES=${5:-json session wait wait1k}
F=~/bench/fw PORT=18500 PIN=0-7,16-23 CPIN=8-15,24-31
mkdir -p $OUT
exec 9> ~/bench/fpm-bench/bench.lock
flock -n 9 || { echo "another benchmark holds ~/bench/fpm-bench/bench.lock"; exit 1; }
ulimit -n 1048576

cookies() { # url file: 2048 sessions, one Cookie header value per line (no User-Agent, as wrk)
    seq 2048 | xargs -P 16 -I{} sh -c "curl -s -o /dev/null -H 'User-Agent:' -D - '$1' | sed -n 's/^set-cookie: \\([^;]*\\).*/\\1/Ip' | paste -sd';' | sed 's/;/; /g'" > $2
}

for r in $(seq $ROUNDS); do
    for n in $NS; do
        for cell in $CELLS; do
            fw=${cell%%:*} s=${cell#*:} base=$OUT/r$r-N$n-$fw-$s
            if ! { uptime && bash $F/srv.sh start $fw $s $n $PIN && bash $F/srv.sh cpus; } > $base-start.txt 2>&1; then
                echo "$(date +%T) $fw $s N=$n failed to start"; bash $F/srv.sh stop; continue
            fi
            for route in $ROUTES; do
                name=${route%1k} c=$((16 * n)) to="" lua=()
                [ $route = wait1k ] && c=1000 to="--timeout 10s"
                path=$(awk -v r=$name '$1==r{print $2}' $F/routes/$fw)
                [ -n "$path" ] || continue
                url=http://127.0.0.1:$PORT$path
                if [ $name = session ]; then
                    cookies $url /tmp/fw-cookies.txt
                    { c1=$(head -1 /tmp/fw-cookies.txt); echo "sessions: $(sort -u /tmp/fw-cookies.txt | grep -c .) distinct of $(wc -l < /tmp/fw-cookies.txt)"
                      echo "cookie: $c1"
                      for i in 1 2 3; do curl -s -H 'User-Agent:' -H "Cookie: $c1" -D - $url | grep -ai 'set-cookie\|^HTTP' | tr -d '\r' | cut -c1-100; curl -s -H 'User-Agent:' -H "Cookie: $c1" $url | head -c 200; echo; done
                    } > $base-session-check.txt 2>&1
                    lua=(-s $F/session.lua $url -- /tmp/fw-cookies.txt 16)
                    url=""
                fi
                f=$base-$route.txt
                taskset -c $CPIN wrk -t16 -c$c $to -d3s ${lua[@]+"${lua[@]}"} $url > /dev/null 2>&1
                (sleep 1; cat /proc/stat; sleep 8; echo --; cat /proc/stat) > /tmp/fw.stat &
                taskset -c $CPIN wrk -t16 -c$c $to -d10s --latency ${lua[@]+"${lua[@]}"} $url > $f 2>&1
                wait
                { echo '## server-stat'; cat /tmp/fw.stat; } >> $f
                echo "$(date +%T) r$r N=$n $fw $s $route: $(awk '/Requests\/sec/{printf "%s req/s ", $2} /^ +99%/{printf "p99 %s ", $2} /Socket errors|Non-2xx/{printf "[%s] ", $0}' $f)"
            done
            bash $F/srv.sh stop >> $base-start.txt 2>&1 || echo "STOP FAILED for $fw $s"
            cp /tmp/fw-srv.log $base-server.log 2>/dev/null
        done
    done
done
