# Task: Swerve loads application code through the plug when ext-phasync is missing

Branch `plug` of swerve, in the worktree `~/dev/swerve-plug`. Local only: never push. The goal
(owner): Swerve serving applications **without** ext-phasync behaves as close as possible to Swerve
**with** it (CPU preemption excepted). Swerve does this itself; nothing to configure.

Read first: `~/dev/phasync-plug/README.md` (Loader, cache mode, findings), `~/dev/phasync-babel/README.md`,
`~/dev/phasync-hooked/README.md` and `src/map.php`, and in this repo `bin/swerve.php`,
`src/Util/WorkerAutoloader.php`, `src/Util/Adapters.php`, `src/Util/Worker.php`.

## 1. Integration

- Require `phasync/plug` (which requires babel and hooked) through Composer path repositories
  (`~/dev/phasync-plug`, `~/dev/phasync-babel`, `~/dev/phasync-hooked`).
- When `extension_loaded('phasync')` is false, each worker installs the plug in cache mode before it
  loads anything of the application: map = phasync-hooked's map; cache directory under
  `sys_get_temp_dir()` (e.g. `swerve-babel-<uid>/<hash of the application root>`), shared by all
  workers (atomic writes: temp file + rename); exclusions = the real paths of phasync, swerve itself,
  phasync/net, babel, hooked and plug, and nothing else (adapters, frameworks and every other
  vendor package are translated).
- Every path through which Swerve loads application code must go through `phasync\plug\path()`:
  the `swerve.php` file, an adapter's entry and whatever the adapter includes from the app, and
  WorkerAutoloader's own includes of non-excluded files (Composer `files` and classes of the
  adapters' dependency closure). Find them all.
- With the extension loaded, nothing of this happens (no plug, no cache).
- The startup log line says which mode the worker runs in.

## 2. The gap list, measured

Run Swerve's own test suite, then each adapter's suite (swerve-psr15, swerve-laravel,
swerve-symfony, swerve-codeigniter, swerve-yii, swerve-cakephp, swerve-laminas, swerve-spiral; one at
a time, never in parallel; point each at this branch through a path repository in a scratch copy, not
in the adapter repos) in two modes: without the extension (= plug) and with it (see swerve's README /
tests for PHASYNC_EXT_SO; take the binary from phasync's 2.0.4 tag:
`git -C ~/dev/phasync show 2.0.4:ext/phasync-8.5-nts-x86_64-glibc.so > <scratch>/phasync.so`).
Record for every test that passes in one mode and fails in the other: the test, the failure, and the
cause (a missing hooked function, a babel limit, an integration gap, a real difference such as
sapi()-level isolation). Also record the suites' wall time per mode.

Do not fix the adapters or the other packages; a fix inside this branch's integration is fine. The
output is `PLUG-GAPS.md` in this worktree: the table of differences with causes, grouped by cause,
most impactful first, and the wall times.

## Rules

Commit on the `plug` branch as you go (messages end with
`Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`). No benchmarks beyond suite wall times.
Start servers only with PID files and stop them by PID; never leave processes running. Scratch in
`/tmp/claude-1000/-home-frode-dev-phasync/cc9b3add-3944-4d60-9ac2-d1ef58b12df0/scratchpad/swerve-plug/`.
The local php loads grpc on PHP 8.4 only; use php8.5. Laminas needs php8.4 without grpc (copy its
conf.d without the grpc ini into a PHP_INI_SCAN_DIR).
