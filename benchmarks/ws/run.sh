#!/bin/bash
# run.sh WORKERS CONNS [EXT=1]: swerve on black with the fan-out app, wsbench from here
W=$1 N=$2 EXTFLAG=${3:-1}
ssh black "bash -s" <<REMOTE
ulimit -n 1048576
cd ~/bench/swerve
X=""; [ "$EXTFLAG" = 1 ] && X="-d extension=\$HOME/bench/phasync.so"
setsid php8.5 \$X -d opcache.enable_cli=1 -d memory_limit=-1 bin/swerve.php --workers=$W -q --no-access-log --http=0.0.0.0:18400 --http=0.0.0.0:18401 benchmarks/ws/swerve.php > /tmp/ws-swerve.log 2>&1 &
echo \$! > /tmp/ws-swerve.pid
sleep 3
REMOTE
before=$(ssh black 'for p in $(ss -ltnp | grep ":18400 " | grep -o "pid=[0-9]*" | cut -d= -f2 | sort -u); do grep VmRSS /proc/$p/status | awk "{print \$2}"; done | paste -sd+ | bc')
"$(dirname "$0")"/client/wsbench -host 192.168.10.15 -ports 18400,18401 -conns $N -rate 5000 -messages 8 -interval 1s -hold 3s -settle 3s > /tmp/ws-client.txt 2>&1 &
C=$!
sleep $(( N / 5000 + 6 ))
after=$(ssh black 'for p in $(ss -ltnp | grep ":18400 " | grep -o "pid=[0-9]*" | cut -d= -f2 | sort -u); do grep VmRSS /proc/$p/status | awk "{print \$2}"; done | paste -sd+ | bc')
sockets=$(ssh black 'ss -tn state established "( sport = :18400 or sport = :18401 )" | tail -n +2 | wc -l')
wait $C
cat /tmp/ws-client.txt
echo "workers=$W sockets(server side)=$sockets RSS: $((before/1024)) MB before, $((after/1024)) MB with sockets open: $(( (after-before) / (sockets>0?sockets:1) )) KiB per socket"
ssh black 'kill -TERM $(cat /tmp/ws-swerve.pid); sleep 3; grep -ciE "error|critical" /tmp/ws-swerve.log'
