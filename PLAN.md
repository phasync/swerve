# Swerve 0.2 — accept() redesign: implementation plan

Working note for the `modes` branch; removed before release. Design agreed with the owner
2026-10-23 (see the project memory); this maps it onto the code.

## Process layout

```
master (bin/swerve.php)            no application code, no pcntl_fork of workers any more
 ├─ opens the HTTP port (unless --no-http), the master socket, the pub/sub inboxes
 └─ proc_open: the worker parent, inheriting fd 3 (listener) and the inbox descriptors
      with ext:    php -d phasync.workers=N -d phasync.workers_listener=3 inc/lifecycle.php app.php
      without ext: php inc/parent.php N app.php   (PHP: forks N children, PHP lot via ext-sockets,
                                                   a child runs one lifecycle and exits)
      └─ worker i: lifecycle after lifecycle of inc/lifecycle.php
```

The master's code is the same both ways: it supervises the worker parent (restart if it dies)
and the lifecycles (through their connections), never forks a worker itself.

## A lifecycle (inc/lifecycle.php)

1. Connect to the master socket (SWERVE_MASTER), send hello: slot (PHASYNC_WORKER), pid.
   The master answers: inbox number, and a start delay (failed-start backoff: the policy stays
   in the master). The connection's end is the lifecycle's end: the master clears its
   subscription bits and frees its inbox.
2. Open its inbox read end (php://fd/N), pin to the NUMA node (first lifecycle only matters).
3. phasync::run(): heartbeat/watchdog, master messages, inbox, then `require app.php`.
4. app.php calls Swerve::accept() as it likes; the lifecycle ends when the script and every
   coroutine end (services are cancelled).

## Cluster features, mapped

| Today | New |
|---|---|
| fork per slot, SIGCHLD | parent forks; master learns pids from hello, a lifecycle's end from EOF |
| heartbeat '.', watchdog SIGQUIT→SIGKILL | same bytes on the lifecycle's connection; kill by pid; the parent replaces the process |
| 'R' ready / 'Q' first request | same; 'R' = first accept() waiting (or the script running, for non-HTTP) |
| recycle (memory, max requests) | lifecycle ends: accept() returns null → reset (ext) / exit (no ext) |
| linger + replacement overlap | `worker_detach()` (new, ext + PHP parent): the parent forks a replacement for the index at once; the detached process finishes its upgraded connections and exits. Up to MAX_LINGERING per index |
| rolling reload (SO_REUSEPORT new listener) | a second worker-parent, of the next generation, on the current code (fresh opcache compile): inherits the same listener and inbox fds, so the lot keeps accepting; once every slot of it is ready, every lifecycle of the old generation drains ('T') together. `worker_detach()` alone can't do this: the worker-parent's own opcache is shared and never revalidates (`validate_timestamps=0`), so detaching a lifecycle within the same long-lived worker-parent would keep serving the old code. A new generation's worker-parent that dies before it is ready leaves the old one serving, and is retried with backoff |
| failed-start backoff, crash loop, exit code | master answers hello with a delay; "no lifecycle ever ready, all failing" → stop with the exit code |
| inboxes made before fork | made by the master before proc_open, all inherited; master assigns one per lifecycle at hello; 4N as today |
| cache / subscriptions over the pipe | same frames over the lifecycle's connection |
| Classic ServerProcess / BuiltinRunner / ServiceServer | deleted |
| orphan reaping (PID 1) | unchanged (the parent is the master's child) |
| master re-exec on reload | no longer needed for a reload: the master has no application code to refresh, and the new worker-parent generation (row above) is what gets a fresh opcache. The master still re-execs itself once at startup, to apply swerve_ini()'s `-d` flags (SWERVE_INI_REEXEC) |

## Requests in a lifecycle

- HttpServer no longer listens: `Swerve::accept()` asks `worker_next()` (ext, or the PHP lot
  client) for a connection when it has no request queued, adopts it, parses, returns the request
  as a fiber running the handler inside sapi().
- Keep-alive: async keeps its connections; Persistent parks its other idle connections when it
  starts a request; Classic parks at the end of its lifecycle (instead of closing).
  App-set `Connection: close` always wins.
- Static files: served by the HTTP server's coroutines, never through accept().
- `accept(?string $webRoot, string|\Closure $handler)`: script path, SAPI closure (no params),
  PSR-7 closure (first param ServerRequestInterface → ResponseInterface). Request $_SERVER =
  worker $_SERVER at accept() + request vars; throws if it holds an object.
- `--no-http`: no listener; accept() throws.
- CLI: bare `swerve` runs ./swerve.php; absent one it fails, suggesting `swerve -S [root] [router.php]`
  (FPM-style runner; `-S` alone ≡ `-S ./ index.php`) and `swerve some-app.php` (that file in every worker).
- accept() inside a request (inside sapi()) throws LogicException: it is the worker script's loop.
- No framework adapters: Laravel (stock Octane), Symfony, PSR-15 get swerve.php recipes in the docs.

## Order of work (tests first in each)

No transitional support for the Mode classes (owner 2026-10-23: they are not part of 0.2):
the test fixtures (17 files, ~41 Mode calls) move to accept() in the same change as the master.

1. ext: `worker_detach()` in the C parent.
2. Master + lifecycle on the new layout (ext path) together with accept(), keep-alive parking,
   --no-http and the CLI forms; Mode classes, Dispatcher modes, Classic runners and the services
   protocol deleted; fixtures converted. Fixes swerve#63's path.
3. PHP parent + PHP lot (no ext): deferred (owner 2026-10-23) until the ext path works and the FPM
   comparison is in; then the same tests pass both ways.
4. Docs (README, docs/), memory of the contract.
5. FPM comparison (hello world, WordPress) on black.
