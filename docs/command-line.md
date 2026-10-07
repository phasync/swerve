# Command line

```
Usage: swerve [options] [swerve.php]

Application:
  [swerve.php]                     A PHP file returning a Swerve\RequestHandler, which runs once per request with a ClientRequest
  --adapter=<name>                 The adapter that provides the entry point: an installed one by name, or swerve for swerve.php (see README, Adapters); without it, the application's composer.json, the only installed adapter, else swerve

Serving:
  --http=<address>                 Serve HTTP here: 8080 (this machine only), :8080 (every interface), host:port, [ipv6]:port or unix:/path; repeat for several (default: 127.0.0.1:8080)
  --public=<dir>                   HTTP: serve the files in this directory (CSS, JavaScript, images), and pass the rest to the application
  --trusted-proxy=<ip|range|unix>  HTTP: believe the X-Forwarded-For, -Proto and -Host of a proxy at this IP address, range (10.0.0.0/8) or unix (unix: sockets); repeat for several
  -w, --workers=<n>                Worker processes; auto is one per CPU core (default: auto)

Extension:
  --ext                            Load phasync-ext (bundled with phasync) even if composer.json does not enable it; swerve stops if it cannot

Development:
  --watch                          Restart all workers when a PHP file of the application changes

Logging (to the terminal, or with --log to a file):
  -v, --verbose                    Log more: -v also what swerve does (workers starting, draining), -vv also debug
  -q, --quiet                      Log nothing to the terminal (--log still logs to its file)
  --no-access-log                  No line per request
  --log=<path>                     Append the log, and PHP errors, to this file instead

Limits:
  --max-body=<bytes>               HTTP: the largest request body in bytes (413), 0 for no limit (default: 8388608)
  --grace=<seconds>                Seconds workers get to clean up on shutdown, reload and recycle before SIGKILL (default: 30)
  --linger=<seconds>               Seconds a recycled worker may keep serving its upgraded connections (101) after its replacement took over; 0 = not at all (default: 1800)
  --watchdog=<seconds>             Replace a worker whose event loop is stuck this long (CPU work that never yields counts); at least 1, 0 = off (default: 30)
  --max-memory=<size|P%>           Recycle a worker above this memory after gc: bytes, K, M or G, or a % of memory_limit; 0 = off (default: 80%)
  --max-requests=<n>               Recycle a worker after about n requests; 0 = off (default: 0)
  --cache-size=<size>              The most Swerve::cache() holds, shared by the workers in the master: bytes, K, M or G (default: 64M)

Information:
  -h, --help                       This help
  --version                        The versions of swerve, PHP, phasync and phasync-ext
```

- Options and the application file may come in any order. An option's value is attached
  (`--workers=4`, `-w4`) or the next word (`--workers 4`, `-w 4`); flags may be grouped
  (`-vv`).
- A usage error prints one line to stderr and exits with code 2.
- Addresses: `8080` is 127.0.0.1:8080, this machine only; `:8080` is every IPv4 interface;
  `localhost:8080`, `192.168.1.10:8080` and `[::1]:8080` are that address. A host name is
  resolved once, at start. `--http` may be given more than once.
- `unix:/run/swerve.sock` (also `unix:///run/swerve.sock`, or just `/run/swerve.sock`) listens on a
  Unix domain socket, for nginx on the same machine: `proxy_pass http://unix:/run/swerve.sock;`.
  The workers share the one socket file, made at start (a stale file is replaced; where anything
  listens, or a file that isn't a socket is in the way, swerve refuses to start) and removed at
  stop. Anyone may connect: limit access with the permissions of the directory it is in.
- `--adapter` picks what provides the entry point when a package such as a framework adapter is installed, see
  [Adapters](../README.md#adapters). A `swerve.php` argument cannot be combined with an adapter other than `swerve`.
- `--public` and `--max-body` apply to HTTP, which is all swerve serves; a proxy in front (nginx,
  HAProxy, Caddy) speaks HTTP to it.
- `--trusted-proxy` names the proxies in front of swerve: an address (`10.0.0.5`, `::1`), a range
  (`10.0.0.0/8`, `fd00::/8`) or `unix` for clients on a Unix socket. For a request from one of them,
  `peer()` is the rightmost `X-Forwarded-For` address that is not a trusted proxy, `getScheme()` is
  `https` when `X-Forwarded-Proto` is `https`, and the `host` header is `X-Forwarded-Host`. From any
  other peer these headers are left as they came, and mean nothing to swerve.

## Exit codes

| | |
|---|---|
| 0 | stopped by a signal, after draining |
| 1 | could not start: the address is taken, the log file can't be opened, the application file is missing |
| 2 | a usage error; or the application can't start: loading it threw, or it returned something other than a `Swerve\RequestHandler` |
| 255 | the application can't start because of a PHP fatal error, such as a syntax error (when every worker fails to start, swerve stops with the last one's exit code) |

## Signals to the master process

| | |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C), `SIGQUIT` | stop: workers finish their requests within `--grace`, then are killed (workers lingering after a recycle too); a second signal kills at once |
| `SIGHUP`, `SIGUSR2` | reopen the log file, and reload: stop every worker, then restart swerve in place (same PID) running the current code |
| `SIGUSR1` | reopen the log file (after log rotation) |

Next: [Production](production.md).
