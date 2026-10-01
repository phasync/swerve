# Command line

```
Usage: swerve [options] [swerve.php]

Application:
  [swerve.php]            A PHP file returning a PSR-15 RequestHandlerInterface, such as a Slim app

Serving:
  --http=<address>        Serve HTTP here: 8080 (this machine only), :8080 (every interface), host:port, [ipv6]:port or unix:/path; repeat for several (default: 127.0.0.1:8080)
  --fastcgi=<address>     Serve FastCGI here instead, behind nginx or the like; the same forms as --http
  --public=<dir>          HTTP: serve the files in this directory (CSS, JavaScript, images), and pass the rest to the application
  -w, --workers=<n>       Worker processes; auto is one per CPU core (default: auto)

Development:
  --watch                 Reload the workers, one at a time, when a PHP file of the application changes

Logging (to the terminal, or with --log to a file):
  -v, --verbose           Log more: -v also what swerve does (workers starting, draining), -vv also debug
  -q, --quiet             Log nothing to the terminal (--log still logs to its file)
  --no-access-log         No line per request
  --log=<path>            Append the log, and PHP errors, to this file instead

Limits:
  --max-body=<bytes>      HTTP: the largest request body in bytes (413), 0 for no limit (default: 8388608)
  --buffer-responses      HTTP: send each response body whole (up to 8 MiB) with a Content-Length, instead of streaming it
  --grace=<seconds>       Seconds workers get to finish their requests on shutdown, reload and recycle before SIGKILL (default: 30)
  --watchdog=<seconds>    Replace a worker whose event loop is stuck this long (CPU work that never yields counts); at least 1, 0 = off (default: 30)
  --max-memory=<size|P%>  Recycle a worker above this memory after gc: bytes, K, M or G, or a % of memory_limit; 0 = off (default: 80%)
  --max-requests=<n>      Recycle a worker after about n requests; 0 = off (default: 0)
  --cache-size=<size>     The most Swerve::cache() holds, shared by the workers in the master: bytes, K, M or G (default: 64M)

Information:
  -h, --help              This help
  --version               The versions of swerve, PHP, phasync and phasync-ext
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
- `--fastcgi` serves FastCGI instead of HTTP, for nginx or another web server in front;
  `--public`, `--max-body` and `--buffer-responses` are for HTTP mode only.

## Exit codes

| | |
|---|---|
| 0 | stopped by a signal, after draining |
| 1 | could not start: the address is taken, the log file can't be opened, the application file is missing |
| 2 | a usage error; or the application can't start: loading it threw, or it returned no request handler |
| 255 | the application can't start because of a PHP fatal error, such as a syntax error (when every worker fails to start, swerve stops with the last one's exit code) |

## Signals to the master process

| | |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C), `SIGQUIT` | stop: workers finish their requests within `--grace`, then are killed; a second signal kills at once |
| `SIGHUP`, `SIGUSR2` | reopen the log file, and reload: replace the workers one at a time with ones running the current code |
| `SIGUSR1` | reopen the log file (after log rotation) |

Next: [Production](production.md).
