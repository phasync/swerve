# Command line

```
Usage: swerve [options] [swerve.php]
       swerve [options] -S [dir] [router.php]

Application:
  [swerve.php]           The application's runner, which calls Swerve::accept() (default: ./swerve.php)
  -S [dir] [router.php]  Serve a directory as php -S does: its files, its PHP files run, every
                         other request to the router (default: dir's index.php; without one, 404).
                         dir defaults to the current directory. Not with a swerve.php

Options:
  --http=<address>       Serve HTTP here: host:port, or unix:/path (default: 127.0.0.1:8080)
  --workers=<n>          Worker processes (default: one per CPU core)
  --log=<path>           Append the log, and the workers' PHP errors, to this file instead of the terminal
  -d name=value          PHP settings go to PHP itself: php -d memory_limit=1G vendor/bin/swerve
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
- `--public` applies to HTTP, which is all swerve serves; a proxy in front (nginx,
  HAProxy, Caddy) speaks HTTP to it.

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
| `SIGTERM`, `SIGINT` (Ctrl+C), `SIGQUIT` | stop: workers finish their requests within `--grace`, then are killed; a second signal kills at once |
| `SIGHUP`, `SIGUSR2` | reopen the log file, and reload: stop every worker, then restart swerve in place (same PID) running the current code |
| `SIGUSR1` | reopen the log file (after log rotation) |

Next: [Production](production.md).
