# Task: MVP of Swerve classic mode on PHP-FPM (ondemand), with FastCGI from Swerve

Branch `classic-fpm` of swerve, worktree `~/dev/swerve-fpm`. Local only: never push. A separate
agent is building the `php -S` pool fallback on branch `classic` (`~/dev/swerve-classic`); don't
touch it. Read its `CLASSIC-TASK.md` for the shared model.

## The model (owner's decisions)

- Classic requests (PHP-FPM semantics: fresh state per request, native header()/echo/session/exit)
  go to PHP-FPM, which Swerve starts, configures and owns. Swerve's own workers keep worker-mode
  requests (concurrent or serialized coroutines) and do the forwarding.
- No pre-started processes: `pm = ondemand`, children forked by FPM only when a request needs one,
  up to `pm.max_children`, exiting after `pm.process_idle_timeout = 10s`.
- When no usable php-fpm is found, classic mode falls back to the `php -S` pool (just log
  "php-fpm not found, classic mode would use php -S" in this MVP and stop; the fallback is the
  other branch).

## MVP scope

1. **Find php-fpm** matching the running PHP exactly (same version): `php-fpm<major.minor>` next to
   `PHP_BINARY` or on PATH, `/usr/sbin/php-fpm<major.minor>`, then `php-fpm`; verify with
   `php-fpm -v`. Report what was found in the startup log.
2. **Start it** from the Swerve master, as its child: a generated config in a private temp dir
   (`daemonize = no`, pid, error log into Swerve's log, `listen` on a Unix socket in that dir with
   mode 0600, `pm = ondemand`, `pm.max_children` from an option, `pm.process_idle_timeout = 10s`,
   `clear_env = no` or explicit env as needed, opcache settings left to php.ini). Stop it on
   Swerve shutdown (SIGQUIT for graceful), restart it if it dies, and pass a Swerve reload on to it
   (SIGUSR2) so new code is picked up. No leftover processes, ever.
3. **FastCGI client in Swerve** (pure PHP over phasync\Net, no extension needed): `FCGI_RESPONDER`
   with `FCGI_KEEP_CONN`, connections to FPM kept open and reused per worker, one request at a
   time per connection, new connections on demand. Params: `SCRIPT_FILENAME`, `SCRIPT_NAME`,
   `DOCUMENT_ROOT`, `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `PATH_INFO` where applicable,
   `SERVER_PROTOCOL`, `SERVER_NAME`, `SERVER_PORT`, `SERVER_ADDR`, `REMOTE_ADDR`, `REMOTE_PORT`,
   `HTTPS` (when TLS or a trusted proxy says so), `CONTENT_TYPE`, `CONTENT_LENGTH`, every request
   header as `HTTP_*`, `GATEWAY_INTERFACE`, `SERVER_SOFTWARE`. Stream the request body into
   `FCGI_STDIN` as it arrives; parse `FCGI_STDOUT` into the status (`Status:` header) and headers,
   then stream the body to the client as records arrive; `FCGI_STDERR` to Swerve's log.
4. **Routing like `php -S` / nginx's usual front-controller setup**: an existing `.php` file runs;
   an existing static file is served by Swerve directly; everything else goes to `index.php`;
   dotfiles denied. A way to start it, e.g. `swerve --classic --root=<docroot>` (pick something
   consistent with swerve's existing options; document it in the help text).
5. **Tests** (Pest, in swerve's style): GET/POST, query, cookies, multiple Set-Cookie, an upload,
   a streamed response (echo, flush(), sleep 0.2 s, echo: the client gets the first part first),
   a 404 via the front controller, exit(), REMOTE_ADDR/HTTPS passed through, FPM restarted after
   being killed, no leftover processes after shutdown, idle children gone after 10 s.

## Benchmark on black (after the tests pass)

Same method as the `classic` branch's task (`~/bench/ext-baseline.sh` style: servers on cores 0-1,
wrk on 8-15, c=32, CPU per request from cores 0-1 busy ticks in /proc/stat, which counts every
process): WordPress front page (`~/bench/wp/wordpress`, siteurl http://127.0.0.1:18500), Swerve
classic on FPM ondemand (max_children 16) vs nginx + PHP-FPM ondemand 16 and static 16
(`~/bench/wp/fpm-serve.sh`). req/s, CPU ms/req, cores busy %, median and p99 latency; plus cold
start (first 20 requests at c=4 from zero children). **Another agent benchmarks on black on the
same port: before each run, check `pgrep -af swerve-classic-bench` and wait until nothing of it
runs.** Work under `/tmp/swerve-fpm-bench` on black; validate each mode with a single request (a
real 200 page of the expected size) before measuring; stop everything by PID after each run.

Write `FPM-RESULTS.md`; commit on the branch (messages end with
`Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`).
