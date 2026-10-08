#!/bin/bash
# srv.sh start SERVER N [PIN] | stop | cpus: runs on black. One server at a time on port 18500, in a
# session (process group) of its own. PIN (e.g. 0-7,16-23) starts it under taskset, so every
# process and thread it creates inherits the CPU set. stop kills that process group only.
# SERVER: swerve swerve-ext rr franken swoole openswoole react
set -u
export PATH=~/bench/bin:$PATH
B=~/bench A=~/bench/servers/apps S=~/bench/servers
# The shared PSR-15 application (apps/*.php); NATIVE=1: each server's own API without PSR-7 (apps/native)
AP=$A; [ -n "${NATIVE:-}" ] && AP=$A/native
PORT=18500 STATE=/tmp/srv.pgid LOG=/tmp/srv.log
# Identical opcache + JIT for every server that runs PHP's CLI; FrankenPHP gets the same through php_ini
PHPF=(-d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d opcache.jit=1054 -d opcache.jit_buffer_size=128M)

tree() { # the leader and all its descendants (RoadRunner puts its PHP workers in groups of their own)
    local p=$1; echo $p
    for c in $(ps -eo pid=,ppid= | awk -v p=$p '$2==p{print $1}'); do tree $c; done
}

stop() {
    [ -f $STATE ] || return 0
    local p=$(cat $STATE)
    local all=$(tree $p)
    kill -TERM -- -$p 2>/dev/null
    for i in $(seq 50); do [ -z "$(ps -eo pgid= | tr -d ' ' | grep -x "$p")" ] && break; sleep 0.2; done
    kill -KILL -- -$p 2>/dev/null
    for i in $(seq 25); do [ -z "$(ps -eo pgid= | tr -d ' ' | grep -x "$p")" ] && break; sleep 0.2; done
    for q in $all; do kill -KILL $q 2>/dev/null; done # any descendant that outlived its group
    left=$(ps -eo pgid= | tr -d ' ' | grep -cx "$p")
    rm -f $STATE
    # nothing may still hold the port
    for pt in 18500 18400 18401; do ss -ltnH "sport = :$pt" | grep -q . && { echo "port $pt still in use"; return 1; }; done
    [ "$left" = 0 ] || { echo "$left processes left in group $p"; return 1; }
}

case $1 in
stop) stop; exit ;;
rss) # resident memory of the whole server (every process), in KiB
    for pid in $(tree $(cat $STATE)); do awk '/VmRSS/{print $2}' /proc/$pid/status; done | paste -sd+ | bc
    exit ;;
cpus) # every process and thread of the running server, grouped by their allowed CPUs
    p=$(cat $STATE)
    for pid in $(tree $p); do
        for t in /proc/$pid/task/*; do
            echo "$(cat /proc/$pid/comm) $(awk '/Cpus_allowed_list/{print $2}' $t/status)"
        done
    done | sort | uniq -c
    exit ;;
esac

T=$2 N=$3 PIN=${4:-}
stop >/dev/null 2>&1
ulimit -n 1048576
cd $S
case $T in
swerve)     cmd=(php8.5 "${PHPF[@]}" $B/swerve/bin/swerve.php --workers=$N -q --no-access-log --http=0.0.0.0:$PORT $AP/swerve.php) ;;
swerve-ext) cmd=(php8.5 -d extension=$B/phasync.so "${PHPF[@]}" $B/swerve/bin/swerve.php --workers=$N -q --no-access-log --http=0.0.0.0:$PORT $AP/swerve.php) ;;
rr)
    cat > $S/.rr.yaml <<YAML
version: "3"
server:
  command: "php8.5 -d extension=$B/ext/protobuf.so ${PHPF[*]} $AP/rr-worker.php"
  relay: pipes
http:
  address: 0.0.0.0:$PORT
  access_logs: false
  pool:
    num_workers: $N
    max_jobs: 0
    allocate_timeout: 60s
    destroy_timeout: 5s
logs:
  mode: production
  level: error
YAML
    cmd=($B/rr/rr serve -c $S/.rr.yaml -w $S) ;;
franken)
    # The official Docker image's binary and libraries (glibc, no ext-parallel), run natively; see SUMMARY.md
    R=$B/franken-deb/rootfs
    cat > $S/.Caddyfile <<CADDY
{
	auto_https off
	admin off
	log {
		level ERROR
	}
	frankenphp {
		num_threads ${FRANKEN_THREADS:-$((N + 1))}
		php_ini opcache.enable 1
		php_ini opcache.validate_timestamps 0
		php_ini opcache.jit 1054
		php_ini opcache.jit_buffer_size 128M
	}
}
:$PORT {
	root * $A
	php_server {
		file_server off
		worker {
			file $AP/franken-worker.php
			num $N
			match *
		}
	}
}
CADDY
    export GODEBUG=cgocheck=0
    cmd=($R/lib64/ld-linux-x86-64.so.2 --library-path $R/usr/local/lib:$R/usr/lib/x86_64-linux-gnu:$R/lib/x86_64-linux-gnu $R/usr/local/bin/frankenphp run --config $S/.Caddyfile)
    # FRANKEN_BIN=static: the release's static glibc build instead (has ext-parallel; deadlocks, see SUMMARY.md)
    [ "${FRANKEN_BIN:-}" = static ] && cmd=($B/franken/frankenphp run --config $S/.Caddyfile) ;;
swoole)     export WORKERS=$N SWMODE=${SWMODE:-base}; cmd=(php8.5 -d extension=$B/ext/swoole.so "${PHPF[@]}" $AP/swoole.php) ;;
openswoole) export WORKERS=$N SWMODE=${SWMODE:-base}; cmd=(php8.5 -d extension=$B/ext/openswoole.so "${PHPF[@]}" $AP/openswoole.php) ;;
react)      export PORT; cmd=(bash -c 'for i in $(seq '$N'); do php8.5 -d extension='$B'/ext/ev.so '"${PHPF[*]}"' '$A'/react.php & done; wait') ;;
# WebSocket fan-out servers on ports 18400 and 18401 (/news, POST /publish), no memory limit
ws-swerve)     PORT=18400; cmd=(php8.5 -d memory_limit=-1 "${PHPF[@]}" $B/swerve/bin/swerve.php --workers=$N -q --no-access-log --http=0.0.0.0:18400 --http=0.0.0.0:18401 $B/swerve/benchmarks/ws/swerve.php) ;;
ws-swerve-ext) PORT=18400; cmd=(php8.5 -d extension=$B/phasync.so -d memory_limit=-1 "${PHPF[@]}" $B/swerve/bin/swerve.php --workers=$N -q --no-access-log --http=0.0.0.0:18400 --http=0.0.0.0:18401 $B/swerve/benchmarks/ws/swerve.php) ;;
ws-swoole)     PORT=18400; export WORKERS=$N; cmd=(php8.5 -d extension=$B/ext/swoole.so -d memory_limit=-1 "${PHPF[@]}" $A/ws/swoole.php) ;;
ws-openswoole) PORT=18400; export WORKERS=$N; cmd=(php8.5 -d extension=$B/ext/openswoole.so -d memory_limit=-1 "${PHPF[@]}" $A/ws/openswoole.php) ;;
ws-react)      PORT=18400; export WORKERS=$N; rm -f /tmp/react-ws-*.sock
               cmd=(bash -c 'for i in $(seq 0 '$((N - 1))'); do INDEX=$i php8.5 -d extension='$B'/ext/ev.so -d memory_limit=-1 '"${PHPF[*]}"' '$A'/ws/react.php & done; wait') ;;
*) echo "unknown server $T"; exit 1 ;;
esac
[ -n "$PIN" ] && cmd=(taskset -c $PIN "${cmd[@]}")
setsid "${cmd[@]}" > $LOG 2>&1 < /dev/null &
echo $! > $STATE
up() { case $T in ws-*) [ "$(curl -s -o /dev/null -w '%{http_code}' -m 1 http://127.0.0.1:$PORT/)" != 000 ] ;; *) [ "$(curl -s -m 1 http://127.0.0.1:$PORT/)" = Hello ] ;; esac; }
for i in $(seq 100); do up && break; sleep 0.2; done
up || { echo "$T did not start"; cat $LOG; stop; exit 1; }
sleep 1 # every worker/process up
case $T in ws-*) exit 0 ;; esac
for r in / /json /wait /page; do
    c=$(curl -s -o /dev/null -w '%{http_code}:%{size_download}' http://127.0.0.1:$PORT$r); echo -n "$r=$c "
done
echo
