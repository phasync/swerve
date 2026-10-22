# Command line

```
Usage: swerve [options] [dir|router.php] [router.php]

Application (without a swerve.php, as php -S: Classic mode):
  [dir|router.php]                 A directory: the public root (default: the current directory); a PHP file: the router, run for every path that does not exist (default: index.php in the public root)
  [router.php]                     After a public root: the router
  -t, --docroot=<dir>              The public root, as for php -S

Serving:
  --http=<address>                 Serve HTTP here: 8080 (this machine only), :8080 (every interface), host:port, [ipv6]:port or unix:/path; repeat for several (default: 127.0.0.1:8080)
  --trusted-proxy=<ip|range|unix>  HTTP: believe the X-Forwarded-For, -Proto and -Host of a proxy at this IP address, range (10.0.0.0/8) or unix (unix: sockets); repeat for several
  -w, --workers=<n>                Worker processes; auto is one per CPU core (default: auto)
  --classic-workers=<n>            Classic: the most PHP processes running scripts at once, as PHP-FPM's pm.max_children with pm = ondemand; auto is the number of workers (default: auto)

Extension:
  --ext                            Load ext-phasync (bundled with phasync); swerve stops if it cannot

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
  --version                        The versions of swerve, PHP, phasync and ext-phasync
```

- With a `swerve.php` in the current directory, `swerve` runs what it returns (see
  [Getting started](getting-started.md)); a public root or router on the command line beside it is
  a usage error: configure them in `swerve.php`. swerve never looks in parent directories, and
  `composer exec swerve` runs in the directory of the nearest `composer.json`.
- Without one, the command line is shorthand for `ClassicMode`, as with `php -S`; paths are relative
  to the current directory:

  | Command | Public root | Router |
  |---|---|---|
  | `swerve` | `.` | `./index.php` |
  | `swerve public` or `swerve -t public` | `public/` | `public/index.php` |
  | `swerve router.php` | `.` | `./router.php` |
  | `swerve public router.php` | `public/` | `./router.php` |

  A positional argument that is a directory is the public root; a file is the router; anything else
  is a usage error. Unlike `php -S`, the router runs only for paths that don't exist.
- Options and arguments may come in any order. An option's value is attached
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
- `--classic-workers` bounds a Classic application's PHP processes, as PHP-FPM's `pm.max_children`
  with `pm = ondemand`: they start as requests need them, from any worker, and exit after 30 seconds
  idle.
- `--max-body` applies to HTTP, which is all swerve serves; a proxy in front (nginx, HAProxy, Caddy)
  speaks HTTP to it.
- `--trusted-proxy` names the proxies in front of swerve: an address (`10.0.0.5`, `::1`), a range
  (`10.0.0.0/8`, `fd00::/8`) or `unix` for clients on a Unix socket. For a request from one of them,
  `peer()` is the rightmost `X-Forwarded-For` address that is not a trusted proxy, `getScheme()` is
  `https` when `X-Forwarded-Proto` is `https`, and the `host` header is `X-Forwarded-Host`. From any
  other peer these headers are left as they came, and mean nothing to swerve.

## Exit codes

| | |
|---|---|
| 0 | stopped by a signal, after draining |
| 1 | could not start: the address is taken, the log file can't be opened |
| 2 | a usage error (no `index.php` or router to serve); or the application can't start: loading `swerve.php` threw (a public root or router that doesn't exist, a handler no adapter runs), or it returned something other than a mode |
| 255 | the application can't start because of a PHP fatal error, such as a syntax error (when every worker fails to start, swerve stops with the last one's exit code) |

## Signals to the master process

| | |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C), `SIGQUIT` | stop: workers finish their requests within `--grace`, then are killed (workers lingering after a recycle too); a second signal kills at once |
| `SIGHUP`, `SIGUSR2` | reopen the log file, and reload: stop every worker, then restart swerve in place (same PID) running the current code |
| `SIGUSR1` | reopen the log file (after log rotation) |

Next: [Production](production.md).
