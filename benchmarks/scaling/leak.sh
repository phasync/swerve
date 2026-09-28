#!/bin/bash
# RSS growth per request of one swerve worker: leak.sh <label> <dir> <bin> <script> <path> [php options...]
export PATH=~/bench/bin:$PATH
label=$1 dir=$2 bin=$3 script=$4 path=$5; shift 5
U=http://192.168.10.15:19300
cd "$dir"
setsid php "$@" $bin --workers=1 --http=192.168.10.15:19300 --no-access-log -q $script > /tmp/leak.log 2>&1 & p=$!
for _ in $(seq 100); do curl -sf -o /dev/null $U$path && break; sleep 0.1; done
timeout 20 ssh frode@192.168.10.4 "wrk -t1 -c16 -d2s $U$path" > /dev/null # warm-up
w=$(ps -o pid= --sid $p | sed -n 2p); r0=$(ps -o rss= -p $w)
n=$(timeout 20 ssh frode@192.168.10.4 "wrk -t1 -c16 -d5s $U$path" | awk '/requests in/ {print $1}')
r1=$(ps -o rss= -p $w)
printf '%-28s %7s requests, RSS %6d KB -> %7d KB: %6d bytes/request\n' "$label" "$n" $r0 $r1 $(( (r1 - r0) * 1024 / n ))
kill $p; timeout 40 tail --pid=$p -f /dev/null
