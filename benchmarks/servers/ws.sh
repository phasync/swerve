#!/bin/bash
# ws.sh OUT "SERVERS" "WORKERS:SOCKETS ..." [ROUNDS]: WebSocket fan-out, run from home.
# LO=1 (the main set): server on black pinned to CCD0 (0-7,16-23), wsbench on black pinned to
# CCD1 (8-15,24-31) against 127.0.0.1, with the client CCD's CPU share sampled while messages flow.
# Otherwise wsbench runs here, over the LAN.
# Per round, configuration and server: start the server on black (srv.sh ws-<server>), open SOCKETS
# connections from here at 5,000/s over ports 18400 and 18401 with wsbench (../ws/client), publish
# 8 timestamped messages 1 s apart, and measure when each reaches every socket (p50, p99, last:
# one clock, the client's). Memory per socket: the server's total RSS (every process) with the
# sockets open, minus before, divided by the sockets the server holds.
set -u
OUT=$1 SERVERS=$2 CONFIGS=$3 ROUNDS=${4:-1}
WSB=/home/frode/dev/swerve/benchmarks/ws/client/wsbench
LO=${LO:-} pin="" sub=ws
[ -n "$LO" ] && pin=0-7,16-23 sub=ws-lo
mkdir -p $OUT/$sub
ssh black 'exec 9> ~/bench/fpm-bench/bench.lock; flock -n 9 || { echo LOCKED; exit 1; }; echo HELD; exec cat > /dev/null' < <(exec sleep 1000000) > /tmp/ws-lock.$$ 2>&1 &
LOCKPID=$!; trap 'kill $LOCKPID 2>/dev/null' EXIT
sleep 2; grep -q HELD /tmp/ws-lock.$$ || { echo "black's bench lock is held by another benchmark"; exit 1; }
for r in $(seq $ROUNDS); do
    for cfg in $CONFIGS; do
        w=${cfg%:*} n=${cfg#*:}
        for s in $SERVERS; do
            f=$OUT/$sub/r$r-$s-w$w-$n.txt
            ssh black "cd ~/bench/servers && ${ENVS:-} ./srv.sh start ws-$s $w $pin && ./srv.sh cpus" > $f 2>&1 || { echo "$s failed to start"; cat $f; continue; }
            before=$(ssh black '~/bench/servers/srv.sh rss')
            args="-ports 18400,18401 -conns $n -rate 5000 -messages 8 -interval 1s -hold 3s -settle 3s"
            if [ -n "$LO" ]; then
                # /proc/stat over the 8 s in which the messages are published (after connecting and the hold)
                ssh black "ulimit -n 1048576; (sleep $(( n / 5000 + 4 )); cat /proc/stat; sleep 8; echo --; cat /proc/stat) > /tmp/ws.stat & taskset -c 8-15,24-31 ~/bench/wsbench -host 127.0.0.1 $args; wait; echo '## server-stat'; cat /tmp/ws.stat" > $f.client 2>&1 &
            else
                ulimit -n 1048576; $WSB -host 192.168.10.15 $args > $f.client 2>&1 &
            fi
            C=$!
            sleep $(( n / 5000 + 6 ))
            after=$(ssh black '~/bench/servers/srv.sh rss')
            sockets=$(ssh black 'ss -tnH state established "( sport = :18400 or sport = :18401 )" | wc -l')
            wait $C
            { cat $f.client; echo "## server=$s workers=$w sockets(server side)=$sockets rss_before_kib=$before rss_with_sockets_kib=$after per_socket_kib=$(( (after - before) / (sockets > 0 ? sockets : 1) ))"; } >> $f
            rm -f $f.client
            ssh black '~/bench/servers/srv.sh stop; echo "log lines with error/fatal: $(grep -ciE "error|fatal" /tmp/srv.log)"' >> $f 2>&1
            echo "$(date +%T) r$r $s w=$w n=$n: $(grep -E '^(connected|still)' $f | tr '\n' ' ') $(tail -2 $f | head -1 | grep -o 'per_socket_kib=[0-9]*')"
        done
    done
done
