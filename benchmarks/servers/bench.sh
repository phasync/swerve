#!/bin/bash
# bench.sh MODE OUT "SERVERS" "NS" "SCENARIOS" [ROUNDS]: runs on the client machine (home).
#   MODE lan: servers on black (all 32 CPUs), wrk -t32 here over the 1 Gbit LAN.
#   MODE lo:  servers on black pinned to CCD0 (0-7,16-23), wrk -t16 on black's CCD1 (8-15,24-31)
#             against 127.0.0.1.
# For each round, N and server (servers interleaved within a round): start the server, then per
# scenario a 3 s warm-up and a 10 s measurement with wrk -c16N --latency, while /proc/stat is
# sampled on both sides (1 s into the run, 8 s apart) and black's qdisc drops are read before and
# after. The server is stopped (its whole process group) before the next one starts.
# Raw output: OUT/<mode>/r<round>-N<n>-<server>-<scenario>.txt and ...-start.txt
set -u
MODE=$1 OUT=$2 SERVERS=$3 NS=$4 SCEN=$5 ROUNDS=${6:-2}
BLACK=black HOSTIP=192.168.10.15 PORT=18500 PIN=0-7,16-23 CPIN=8-15,24-31
# wait1k and wait10k: /wait with 1,000 and 10,000 connections (whatever N is), wrk --timeout 10s
declare -A PATHS=([hello]=/ [json]=/json [wait]=/wait [page]=/page [wait1k]=/wait [wait10k]=/wait)
declare -A CONNS=([wait1k]=1000 [wait10k]=10000)
# A server name may carry a variant: "rr*16" runs 16N workers (still -c16N), "franken+gogc400"
# runs with GOGC=400.
dir=$OUT/$MODE; mkdir -p $dir

# One benchmark at a time on black: hold the lock the other benchmark scripts there take
ssh $BLACK 'exec 9> ~/bench/fpm-bench/bench.lock; flock -n 9 || { echo LOCKED; exit 1; }; echo HELD; exec cat > /dev/null' < <(exec sleep 1000000) > /tmp/bench-lock.$$ 2>&1 &
LOCKPID=$!; trap 'kill $LOCKPID 2>/dev/null' EXIT
sleep 2; grep -q HELD /tmp/bench-lock.$$ || { echo "black's bench lock is held by another benchmark"; exit 1; }

drops() { ssh $BLACK "tc -s qdisc show dev eno1" | awk '/dropped/{gsub(",","",$7); s+=$7} END{print s+0}'; }

measure() { # n server scenario round
    local n=$1 s=$2 sc=$3 r=$4 c=${CONNS[$3]:-$(( 16 * $1 ))} p=${PATHS[$3]} f=$dir/r$4-N$1-$2-$3.txt
    local to=""; [ -n "${CONNS[$3]:-}" ] && to="--timeout 10s"
    if [ $MODE = lan ]; then
        wrk -t32 -c$c $to -d3s http://$HOSTIP:$PORT$p > /dev/null 2>&1
        local d0=$(drops)
        ssh $BLACK 'sleep 1; cat /proc/stat; sleep 8; echo --; cat /proc/stat' > $f.sstat &
        local sp=$!
        (sleep 1; cat /proc/stat; sleep 8; echo --; cat /proc/stat) > $f.cstat &
        local cp=$!
        wrk -t32 -c$c $to -d10s --latency http://$HOSTIP:$PORT$p > $f 2>&1
        wait $sp $cp
        local d1=$(drops)
        { echo "## drops $d0 $d1"; echo "## server-stat"; cat $f.sstat; echo "## client-stat"; cat $f.cstat; } >> $f
        rm -f $f.sstat $f.cstat
    else
        ssh $BLACK "ulimit -n 1048576; taskset -c $CPIN wrk -t16 -c$c $to -d3s http://127.0.0.1:$PORT$p > /dev/null 2>&1
            (sleep 1; cat /proc/stat; sleep 8; echo --; cat /proc/stat) > /tmp/lo.stat &
            taskset -c $CPIN wrk -t16 -c$c $to -d10s --latency http://127.0.0.1:$PORT$p 2>&1
            wait
            echo '## server-stat'; cat /tmp/lo.stat" > $f
    fi
    echo "$(date +%T) $MODE r$r N=$n $s $sc: $(awk '/Requests\/sec/{printf "%s req/s ", $2} /^ +99%/{printf "p99 %s ", $2} /Socket errors|Non-2xx/{printf "[%s] ", $0}' $f)"
}

for r in $(seq $ROUNDS); do
    for n in $NS; do
        for s in $SERVERS; do
            pin=""; [ $MODE = lo ] && pin=$PIN
            base=${s%%[*+]*} w=$n env="${ENVS:-}"
            case $s in *\**) w=$(( n * ${s#*\*} )) ;; esac
            case $s in *+gogc*) env="$env GOGC=${s#*+gogc}" ;; esac
            if ! ssh $BLACK "cd ~/bench/servers && uptime && $env ./srv.sh start $base $w $pin && ./srv.sh cpus" > $dir/r$r-N$n-$s-start.txt 2>&1; then
                echo "$s N=$n failed to start"; cat $dir/r$r-N$n-$s-start.txt; ssh $BLACK "~/bench/servers/srv.sh stop"; continue
            fi
            for sc in $SCEN; do measure $n $s $sc $r; done
            ssh $BLACK "~/bench/servers/srv.sh stop" >> $dir/r$r-N$n-$s-start.txt 2>&1 || echo "STOP FAILED for $s"
        done
    done
done
