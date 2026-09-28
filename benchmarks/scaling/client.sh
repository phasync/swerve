#!/bin/bash
# The client side, run over ssh from run.sh on the server:
#   client.sh wrk <conns> <seconds> <url> [cookie-file]   wrk -t32, with this machine's CPU use
#   client.sh cookies <url> <count> <file>                <count> sessions, one Cookie value per line
set -u
here=$(cd "$(dirname "$0")" && pwd)
ulimit -n 1048576
case $1 in
wrk)
    lua=(); [ -n "${5:-}" ] && lua=(-s "$here/session.lua")
    s0=$(cat /proc/stat)
    wrk -t32 -c"$2" -d"$3"s --latency "${lua[@]}" "$4" ${5:+-- "$5" 32}
    printf '%s\n--\n%s\n' "$s0" "$(cat /proc/stat)" | awk -f "$here/cpu.awk" | sed 's/^/client: /'
    ;;
cookies)
    # From the Set-Cookie headers, not a cookie jar: curl drops Secure cookies over http. No
    # User-Agent, as wrk sends none: Spiral signs a session with the client's headers.
    seq "$3" | xargs -P 16 -I{} sh -c "curl -s -o /dev/null -H 'User-Agent:' -D - '$2' | sed -n 's/^set-cookie: \\([^;]*\\).*/\\1/Ip' | paste -sd';' | sed 's/;/; /g'" > "$4"
    ;;
esac
