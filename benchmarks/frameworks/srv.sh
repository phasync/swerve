#!/bin/bash
# srv.sh start FW SERVER N [PIN] | stop | cpus: runs on black. One framework application on one
# server at a time on port 18500, in a session (process group) of its own. PIN (e.g. 0-7,16-23)
# starts it under taskset, so every process and thread it creates inherits the CPU set.
#   FW:     laravel symfony slim spiral yii codeigniter
#   SERVER: swerve swerve-ext rr franken swoole swoole-process react (which apply: see FW's case below)
# PHP settings come from ini directories (PHP_INI_SCAN_DIR=:dir, appended to the default scan
# dir), so that processes a server spawns itself (Octane, RoadRunner) get them too:
#   ini/jit    opcache + tracing JIT (every server)       ini/ext    + phasync-ext
#   ini/rr     + ext-protobuf (RoadRunner's workers)      ini/swoole + ext-swoole
#   ini/react  + ext-ev                                   ini/franken-intl + intl (FrankenPHP)
set -u
export PATH=~/bench/bin:$PATH
B=~/bench F=~/bench/fw I=~/bench/fw/ini
PORT=18500 STATE=/tmp/fw-srv.pgid LOG=/tmp/fw-srv.log

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
    for pt in 18500 18501 18502; do ss -ltnH "sport = :$pt" | grep -q . && { echo "port $pt still in use"; return 1; }; done
    [ "$left" = 0 ] || { echo "$left processes left in group $p"; return 1; }
}

case $1 in
stop) stop; exit ;;
cpus) # every process and thread of the running server, grouped by their allowed CPUs
    p=$(cat $STATE)
    for pid in $(tree $p); do
        for t in /proc/$pid/task/*; do
            echo "$(cat /proc/$pid/comm) $(awk '/Cpus_allowed_list/{print $2}' $t/status)"
        done
    done | sort | uniq -c
    exit ;;
esac

FW=$2 T=$3 N=$4 PIN=${5:-}
stop >/dev/null 2>&1
ulimit -n 1048576

# FrankenPHP (the Docker image's build, see ../servers/SUMMARY.md): a Caddyfile as set 1's, tuned
# per FrankenPHP's performance docs: worker mode, `match *` (no file-system lookups), file_server
# off, num_threads N+1, admin off. $1: the worker script, $2 its root.
franken_caddyfile() {
    cat > /tmp/fw.Caddyfile <<CADDY
{
	auto_https off
	admin off
	log {
		level ERROR
	}
	frankenphp {
		num_threads $((N + 1))
	}
}
:$PORT {
	root * $2
	php_server {
		file_server off
		worker {
			file $1
			num $N
			match *
		}
	}
}
CADDY
    export GODEBUG=cgocheck=0
    cmd=($F/frankenphp run --config /tmp/fw.Caddyfile)
}

# RoadRunner: as set 1 (relay pipes, no max_jobs, no access log, error-level logs), no HTTP
# middleware. $1: the worker command; $2: an RPC address (bridges that talk to RoadRunner need it).
rr_config() {
    cat > /tmp/fw-rr.yaml <<YAML
version: "3"
${2:+rpc:
  listen: tcp://$2}
server:
  command: "$1"
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
    cmd=($B/rr/rr serve -c /tmp/fw-rr.yaml -w $A)
}

ini=jit
case $T in swerve-ext) ini=ext ;; rr) ini=rr ;; swoole*) ini=swoole ;; react) ini=react ;; esac
swerve_opts=()
case $FW in
laravel)
    A=$F/swerve-laravel/tests/Fixtures/app; swerve_opts=(--public=public)
    # Octane restarts each worker after --max-requests (default 500; 0 falls back to 500), a
    # ~145 ms Laravel boot each time: 10^9 here, as swerve and the others never restart
    octane=(php artisan octane:start --host=0.0.0.0 --port=$PORT --workers=$N --max-requests=1000000000)
    case $T in
    rr)      cmd=("${octane[@]}" --server=roadrunner --rpc-port=18501 --log-level=error) ;;
    swoole)  cmd=("${octane[@]}" --server=swoole) ;;
    # Octane's default SWOOLE_PROCESS mode: a second config cache made with OCTANE_SWOOLE_MODE=2
    swoole-process) export APP_CONFIG_CACHE=bootstrap/cache/config-process.php; cmd=("${octane[@]}" --server=swoole) ;;
    franken) # Octane's own Caddyfile stub, tuned as for the other FrankenPHP cells
             sed "s/@THREADS@/$((N + 1))/" $F/Caddyfile.octane > /tmp/fw.Caddyfile
             export GODEBUG=cgocheck=0
             cmd=("${octane[@]}" --server=frankenphp --admin-port=18502 --caddyfile=/tmp/fw.Caddyfile) ;;
    esac ;;
symfony)
    A=$F/swerve-symfony/tests/Fixtures/app
    case $T in
    rr)      export APP_RUNTIME='Runtime\RoadRunnerSymfonyNyholm\Runtime'; rr_config "php public/index.php" ;;
    swoole)  export WORKERS=$N; cmd=(php public/swoole.php) ;;
    swoole-process) export WORKERS=$N SWMODE=process; cmd=(php public/swoole.php) ;;
    # symfony/runtime 7.4's own FrankenPHP worker runner; FRANKENPHP_LOOP_MAX=0: no restart every 500
    franken) export FRANKENPHP_LOOP_MAX=0; franken_caddyfile $A/public/index.php $A/public ;;
    esac ;;
slim)
    A=$F/slim
    case $T in
    rr)      rr_config "php rr-worker.php" ;;
    swoole)  export WORKERS=$N; cmd=(php swoole.php) ;;
    franken) franken_caddyfile $A/franken-worker.php $A ;;
    react)   cmd=(bash -c 'for i in $(seq '$N'); do php react.php & done; wait') ;;
    esac ;;
spiral)
    A=$F/swerve-spiral/benchmarks/app
    case $T in rr) rr_config "php app.php" 127.0.0.1:18501 ;; esac ;;
yii)
    A=$F/swerve-yii/tests/Fixtures/app
    case $T in rr) rr_config "php rr-worker.php" ;; esac ;;
codeigniter)
    A=$F/swerve-codeigniter/tests/Fixtures/app
    case $T in franken) ini=franken-intl; franken_caddyfile $A/public/frankenphp-worker.php $A/public ;; esac ;;
*) echo "unknown framework $FW"; exit 1 ;;
esac
case $T in swerve|swerve-ext) cmd=(php vendor/bin/swerve --workers=$N -q --no-access-log --http=0.0.0.0:$PORT "${swerve_opts[@]}" swerve.php) ;; esac
[ -n "${cmd:-}" ] || { echo "no $T for $FW"; exit 1; }
[ $ini = franken-intl ] && export FRANKEN_EXTRA_LIB=$F/franken-intl/lib
export PHP_INI_SCAN_DIR=:$I/$ini PORT
cd $A
[ -n "$PIN" ] && cmd=(taskset -c $PIN "${cmd[@]}")
echo "cd $A; PHP_INI_SCAN_DIR=$PHP_INI_SCAN_DIR ${cmd[*]}"
setsid "${cmd[@]}" > $LOG 2>&1 < /dev/null &
echo $! > $STATE
probe=$(cat $F/routes/$FW | awk '$1=="json"{print $2}')
up() { [ "$(curl -s -o /dev/null -w '%{http_code}' -m 2 http://127.0.0.1:$PORT$probe)" = 200 ]; }
for i in $(seq 300); do up && break; sleep 0.2; done
up || { echo "$FW $T did not start"; cat $LOG; stop; exit 1; }
sleep 1 # every worker/process up
while read name path; do
    c=$(curl -s -o /dev/null -w '%{http_code}:%{size_download}' "http://127.0.0.1:$PORT$path"); echo -n "$name=$c "
done < $F/routes/$FW
echo
