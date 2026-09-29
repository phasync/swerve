#!/bin/bash
# repro.sh WORKERS THREADS JIT(tracing|disable) [ROUNDS]: start FrankenPHP worker mode on black, hit /json with
# wrk -t32 -c512 4 s at a time until throughput drops to 0 (stall) or ROUNDS pass.
W=$1 T=$2 JIT=$3 R=${4:-15} EXTRA=${EXTRA:-}
# BIN=static (the release's gnu static build, with ext-parallel) or BIN=deb (the official Docker image's
# binary and libraries, extracted to ~/bench/franken-deb/rootfs, run with its own loader; no ext-parallel)
BIN=${BIN:-static}
if [ $BIN = deb ]; then ROOT=/home/frode/bench/franken-deb/rootfs; CMD="$ROOT/lib64/ld-linux-x86-64.so.2 --library-path $ROOT/usr/local/lib:$ROOT/usr/lib/x86_64-linux-gnu:$ROOT/lib/x86_64-linux-gnu $ROOT/usr/local/bin/frankenphp"; else CMD=./frankenphp; fi
ssh black "bash -s" <<REMOTE
cd ~/bench/franken
cat > Caddyfile.diag <<CF
{
	auto_https off
	admin off
	log {
		level ERROR
	}
	frankenphp {
		num_threads $T
		worker {
			file /home/frode/bench/franken/index.php
			num $W
		}
		php_ini opcache.enable 1
		php_ini opcache.jit $JIT
		php_ini opcache.jit_buffer_size 128M
	}
}
:18500 {
	root * /home/frode/bench/franken
	php_server
}
CF
$EXTRA GOTRACEBACK=all setsid $CMD run --config Caddyfile.diag > /tmp/fk.log 2>&1 < /dev/null &
echo \$! > /tmp/fk.pid
for i in \$(seq 50); do curl -s -o /dev/null localhost:18500/ && break; sleep 0.2; done
REMOTE
res=ok
for i in $(seq $R); do
  r=$(wrk -t32 -c512 -d4s http://192.168.10.15:18500/json | awk '/Requests\/sec/{print $2}')
  echo "round $i: $r req/s"
  if [ "${r%.*}" -lt 1000 ]; then res=STALL; break; fi
done
echo "workers=$W threads=$T jit=$JIT extra='$EXTRA' bin=$BIN: $res"
