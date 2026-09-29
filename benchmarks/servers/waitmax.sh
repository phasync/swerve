#!/bin/bash
# waitmax.sh OUT "SERVERS" "NS" "LEVELS" [ROUNDS]: /wait pushed to each server's limit, loopback on
# black (server on CCD0, wrk on CCD1). Per round, N and server: start the server, then for each
# connection count in LEVELS (e.g. "1000 10000 25000 50000 100000") a 3 s warm-up and a 10 s
# measurement, stopping early once throughput grows less than 5% over the previous level.
# Above 25,000 connections the load is split over several wrk processes (-t16/k each, all on CCD1),
# each against its own loopback address (127.0.0.1, .2, ...), so that no destination needs more
# than 25,000 of the 28,000 ephemeral ports; black's port range is not changed.
# Raw output: OUT/waitmax/r<round>-N<n>-<server>-c<conns>.txt (one wrk section per process + /proc/stat).
set -u
OUT=$1 SERVERS=$2 NS=$3 LEVELS=$4 ROUNDS=${5:-1}
dir=$OUT/waitmax; mkdir -p $dir
ssh black 'exec 9> ~/bench/fpm-bench/bench.lock; flock -n 9 || { echo LOCKED; exit 1; }; echo HELD; exec cat > /dev/null' < <(exec sleep 1000000) > /tmp/wm-lock.$$ 2>&1 &
LOCKPID=$!; trap 'kill $LOCKPID 2>/dev/null' EXIT
sleep 2; grep -q HELD /tmp/wm-lock.$$ || { echo "black's bench lock is held by another benchmark"; exit 1; }

load() { # conns duration latency-flag: k wrk processes in parallel on CCD1
    local c=$1 d=$2 lat=$3 k=$(( ($1 + 24999) / 25000 ))
    local t=$(( 16 / k )); [ $t -lt 1 ] && t=1
    echo "ulimit -n 1048576"
    for i in $(seq $k); do
        echo "taskset -c 8-15,24-31 wrk -t$t -c$(( c / k )) --timeout 10s -d$d $lat http://127.0.0.$i:18500/wait > /tmp/wm.$i 2>&1 &"
    done
    echo "wait"
}

for r in $(seq $ROUNDS); do
    for n in $NS; do
        for s in $SERVERS; do
            ssh black "cd ~/bench/servers && ./srv.sh start $s $n 0-7,16-23 && ./srv.sh cpus" > $dir/r$r-N$n-$s-start.txt 2>&1 || { echo "$s failed"; continue; }
            prev=0
            for c in $LEVELS; do
                f=$dir/r$r-N$n-$s-c$c.txt
                k=$(( (c + 24999) / 25000 ))
                ssh black "$(load $c 3s '') ; (sleep 1; cat /proc/stat; sleep 8; echo --; cat /proc/stat) > /tmp/wm.stat & $(load $c 10s --latency); wait
                    for i in \$(seq $k); do echo \"## wrk \$i (127.0.0.\$i)\"; cat /tmp/wm.\$i; done; echo '## server-stat'; cat /tmp/wm.stat" > $f 2>&1
                rps=$(awk '/Requests\/sec/{s+=$2} END{printf "%d", s}' $f)
                echo "$(date +%T) r$r N=$n $s c=$c: $rps req/s, p99 $(awk '/^ +99%/{print $2}' $f | tr '\n' ' ') $(grep -h 'Socket errors' $f | tr -s ' ' | tr '\n' ' ')"
                [ $prev -gt 0 ] && [ $(( rps * 100 )) -lt $(( prev * 105 )) ] && break
                prev=$rps
            done
            ssh black "~/bench/servers/srv.sh stop" >> $dir/r$r-N$n-$s-start.txt 2>&1 || echo "STOP FAILED for $s"
        done
    done
done
