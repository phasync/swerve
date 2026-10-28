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
  --grace=<seconds>      How long a stop waits for the requests in flight before it kills (default: 10)
  --watch                Reload when a PHP file of the application changes (for development)
  -d name=value          PHP settings go to PHP itself: php -d memory_limit=1G vendor/bin/swerve
```

- A usage error prints the reason and the usage to stderr, and exits with code 1.
- `unix:/run/swerve.sock` (also `unix:///run/swerve.sock`, or just `/run/swerve.sock`) listens on a
  Unix domain socket, for nginx on the same machine: `proxy_pass http://unix:/run/swerve.sock;`.
  The socket file is made at start (a stale one is replaced; where anything listens, or a file
  that isn't a socket is in the way, swerve refuses to start) and removed at stop. Anyone may
  connect: limit access with the permissions of the directory it is in.
- `--watch` watches the PHP files below the application's directory (or `-S`'s), leaving out
  `vendor/` and names that start with a dot; `vendor/composer/installed.php` tells when Composer
  ran.

## Exit codes

| | |
|---|---|
| 0 | stopped by a signal |
| 1 | could not start: a usage error, the address is taken, the application file is missing, or the loaded phasync extension is one phasync can't use |

## Signals to the master process

| | |
|---|---|
| `SIGTERM`, `SIGINT` (Ctrl+C) | stop: the listener closes; workers finish the requests in flight, then their WebSockets and event streams get a `WorkerStoppingException` (a WebSocket closes with 1001); what still runs after `--grace` is killed. A second signal kills at once |
| `SIGHUP` | reload: each worker is replaced by one running the code as it is now; the old one stops as above once the new one is ready (a WebSocket closes with 1012: reconnect). A new worker that fails to start leaves the old one serving |

Next: [Production](production.md).
