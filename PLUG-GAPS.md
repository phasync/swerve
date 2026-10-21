# PLUG-GAPS.md

Measured differences between Swerve **without** ext-phasync (the plug: phasync/babel +
phasync/hooked, installed by `Swerve\Util\Plug`, see `bin/swerve.php` and
`src/Util/Plug.php`) and Swerve **with** it, running the real test suites. ext-phasync was
phasync's 2.0.4-tagged binary (`ext/phasync-8.5-nts-x86_64-glibc.so`, and for Laminas
`ext/phasync-8.4-nts-x86_64-glibc.so`), loaded via `PHP_INI_SCAN_DIR` so every swerve process a
suite spawns inherits it, exactly as swerve-psr15's own README documents. Plug mode is the
default environment (no extension anywhere). Every adapter ran from a scratch copy with its own
path repositories to this worktree (`/home/frode/dev/swerve-plug`) and to `phasync/plug`,
`phasync/babel`, `phasync/hooked` — **copies** (`"symlink": false`), not symlinks: a symlinked
`phasync/plug` reaches its own `e2e/app/vendor/phasync/swerve -> ~/dev/swerve` fixture, and a
broad scan (Laravel's `artisan package:discover`/autoload optimisation) walked that into the
forbidden main swerve checkout. Copies don't carry that symlink over (confirmed empirically), so
this is fixed for good in the scratch setup, not a Swerve or plug defect.

Suites covered in both modes: swerve's own, swerve-psr15, swerve-laravel, swerve-codeigniter,
swerve-symfony. **Not reached** (time): swerve-cakephp, swerve-yii, swerve-laminas,
swerve-spiral — each needs the same scratch-copy + path-repo treatment already proven on the
other five; nothing adapter-specific is expected to block it.

## Gaps, grouped by cause, most impactful first

### 1. phasync/plug's cache-mode mirror re-mirrors itself for a framework that locates its own file through Reflection — swerve-symfony: 26 of 27 tests fail

**Evidence.** swerve-symfony plug mode: `26 failed, 1 warning, 1 passed`, 56.81 s. ext mode (same
checkout, same fixture): `0 failed, 1 warning, 27 passed`, 24.24 s. Every plug-mode failure
traces to one fatal error, first hit loading the kernel for the dev-environment test:

```
PHP Fatal error:  Cannot redeclare interface Symfony\Component\EventDispatcher\EventSubscriberInterface
  (previously declared in /tmp/swerve-babel-1000/<hash>/tmp/claude.../vendor/symfony/event-dispatcher/EventSubscriberInterface.php:25)
  in /tmp/swerve-babel-1000/<hash>/tmp/swerve-babel-1000/<hash>/tmp/claude.../vendor/symfony/event-dispatcher/EventSubscriberInterface.php on line 25
```

The second path is the cache directory **prefixed onto itself**: `<cacheDir><cacheDir><real
path>` instead of `<cacheDir><real path>`. Something fed an already-mirrored path back into
`phasync\plug\path()` as if it were an original source path, producing a mirror of the mirror —
a second, distinct compiled copy of the same class, hence the redeclare.

**Cause.** phasync/plug's own README documents, as a known trade-off of `'cache'` mode (not a
bug): *"Reflection's `getFileName()`, an exception's `getFile()`/trace, and PHP's own error
messages, unlike `__FILE__`/`__DIR__`, do show the cache mirror path in this mode... since those
necessarily come from the file PHP actually compiled."* Symfony's compiled DI container
(`var/cache/dev/ContainerXXX/App_KernelDevDebugContainer.php`, generated natively by `bin/console
cache:warmup` *before* the plug ever runs) is exactly the kind of code that locates sibling files
relative to itself at runtime. Once that container file is itself translated and running from the
mirror, anything in it that resolves "where am I" via Reflection (rather than the
`fileConstants`-rewritten `__FILE__`/`__DIR__`, which still correctly report the *original* path)
gets the *mirror* location back, computes a path from there, and that computed path lands back
inside `path()` — which mirrors it a second time, nested under itself. None of the other four
suites tested use an ahead-of-time-compiled, reflection-locating container the way Symfony's does,
which is why this is the only one that hits it.

**What would close it, and how big.** Inside phasync/plug's `Loader` (not fixed here — not this
branch's integration, and not in this task's expanded permission, which covers only the babel
precedence bug): `cacheMirrorFor()`/`resolve()` would need to recognise a `$real` that is already
under `self::$cacheDir` and either refuse to re-mirror it (return it unchanged, the same way an
excluded prefix is handled) or resolve it back to the original tree first. That is a small,
localized, one-method fix — moderate confidence it is sufficient, since the nesting is exactly
one level deep in the evidence above, but not verified beyond this one reproduction. Until then,
the only workaround is `'wrapper'` mode (no mirror, so no self-mirroring possible) for apps with a
compiled, reflection-locating container — at `'wrapper'` mode's own documented cost (no opcache
for translated files).

### 2. phasync/hooked's stream cleanup isn't shielded — swerve-psr15: 2 WebSocket tests fail on a drain warning

**Evidence.** swerve-psr15 plug mode: `2 failed, 75 passed`, 43 s; ext mode: `0 failed, 77
passed`, 41 s. Both failures are the same shape: a passing WebSocket exchange, then the test's own
`rt_clean()` check (*"nothing in the log that a clean run would not have"*) fails because draining
the worker logged this PHP warning:

```
PHP Warning:  Cleanup in a finally block of FiberNNN(terminated, NULL) was cut short by its
cancellation reaching it again; use phasync::shielded() for cleanup that must wait
in vendor/phasync/phasync/src/EventLoop.php on line 2281
```

**Cause.** This is phasync's own diagnostic for exactly what it says: some hooked replacement's
`finally` block (plausibly `fread()`/`fwrite()`'s "restore the stream's blocking mode" cleanup,
called out by name in phasync/hooked's own rule 4 — *"Never leave a stream's blocking mode
changed... it switches it back before returning, also on exceptions"*) is doing that cleanup
without `phasync::shielded()`, so when a worker drain cancels the WebSocket read a second time
while the first cancellation's cleanup is still running, the cleanup itself gets cut short. Not
reproduced under ext (native blocking calls have no PHP-level `finally` for the extension to
interrupt this way), and not hit by swerve-laravel's or swerve-codeigniter's own WebSocket tests
in this run (different drain timing), so this needs a live WebSocket read in flight at the exact
moment of a second cancellation to show up — narrow, but real and reproducible by this test.

**What would close it, and how big.** A `phasync::shielded()` wrap around the relevant stream
restore-mode cleanup in phasync/hooked — a missing hooked function. Small, localized fix in
phasync/hooked (not fixed here — out of this task's permission, which covers only babel).

### 3. Worker boot is measurably slower under the plug — a few of swerve's own supervision-timing tests

**Evidence.** swerve's own suite: plug mode `5 failed, 11 warnings, 1 skipped, 686 passed`,
615.42 s; ext mode `1 failed, 11 warnings, 1 notice, 2 skipped, 688 passed`, 586.18 s (the one
ext-mode failure is an unrelated same-second/next-second date-header race, confirmed unrelated to
either mode). Of the 5 plug-mode failures, 4 are `SupervisionTest` cases whose own budget is 1-3 s
for a worker to reload and start answering the new version — each missed by a small margin (the
worst, ~11 ms over a 10 s boot-wait budget; the others a fraction of a millisecond over a 1-2 s
reload-recovery budget). Re-running the same 4 in isolation: 1 reproduced consistently, the other
3 did not (ordinary system-load flakiness under the full 56-core shared box this ran on). Directly
measured: a cold worker is ready in 0.65 s under the plug vs. 0.04 s with ext-phasync loaded
(`Worker ... ready in ...` log line, same fixture, same machine, one run each).

**Cause.** Every `require`/`include` of a non-excluded file goes through `phasync\plug\path()` ->
`Loader::resolve()`/`cacheMirrorFor()`, which `stat()`s the real file and reads a `.key` sidecar
to confirm the cache mirror is current — on every worker boot, not just the first one, because
each new worker process re-runs the whole autoload bootstrap fresh. That per-file overhead is
small but real, and a worker boots once per lifetime, so it lands squarely on exactly the
operations (reload, recycle, crash-recovery) `SupervisionTest` times tightly.

**Disposition.** Per the owner's ruling for this task, slightly slower worker boot/reload under
the plug is an accepted difference, not a functional gap — listed here, not fixed. (phasync/plug's
own README already notes an in-process memo of resolved paths as a way to cut the per-include
`stat()`/`.key`-read cost on a warm cache; not attempted here.)

### 4. No hooked PDO/mysqli/pgsql driver — accepted, not independently reproduced here

phasync/hooked implements time, stream and process functions only (its own README's "Functions
implemented" list); no database driver is hooked. A worker without ext-phasync that opens a real
database connection inside a coroutine blocks the whole worker for that query's duration, same as
the pre-plug baseline. Per the owner's ruling, this is accepted: list it, don't chase it. None of
the five suites run here exercise a non-SQLite database from inside a coroutine in a way that would
measure this directly (the Laravel and CodeIgniter fixtures use SQLite, whose file I/O is native
per phasync/hooked's own rule 5 — regular files never block — so they don't exhibit this gap
either way).

## Not plug-specific (seen, not counted above)

- swerve-laravel: 1 failure (`WebSocketTest > server push: a route that only forwards
  Swerve::subscribe()`, a message-ordering assertion) in **both** modes, identical. Pre-existing
  flake, unrelated to the plug.
- swerve-codeigniter: 1 failure (`WebSocketTest > server push: every client on both workers...`,
  also a message-ordering assertion) in **both** modes, identical. Same shape as the Laravel one;
  likely the same underlying test-timing sensitivity in the fixture, pre-existing.
- swerve-laravel's own suite already carries two ext-specific known-issue skips
  (`phasync/swerve-laravel#15`, `#16`) that run and pass under the plug instead of skipping —
  expected (the plug doesn't have the C extension's specific process/output-buffer behaviour
  those issues are about), not a new finding.

## Wall times

| Suite | Plug mode | ext mode | Difference |
|---|---|---|---|
| swerve (own) | 615.42 s | 586.18 s | +29.2 s (+5.0%) |
| swerve-psr15 | 42.0 s | 41.0 s | +1.0 s (+2.4%) |
| swerve-laravel | 233.11 s | 198.55 s | +34.6 s (+17.4%) |
| swerve-codeigniter | 47.02 s | 45.09 s | +1.9 s (+4.3%) |
| swerve-symfony | 56.81 s | 24.24 s | +32.6 s (dominated by gap #1's failures, not a clean timing comparison) |

Not run (time): swerve-cakephp, swerve-yii, swerve-laminas, swerve-spiral.

phasync/babel's own corpus check (not a mode comparison; verifies the ternary-precedence fix,
below): 127,010 real-world files (WordPress, Laravel, Symfony, CakePHP, CodeIgniter, Drupal,
Joomla, Matomo, MediaWiki, Moodle, Nextcloud, phpBB, PrestaShop from
`~/dev/swerve-sapi/acceptance/apps/`), 342.5 s with 48 jobs, 0 lint failures, 0 mismatches against
the php-parser oracle.

## Fixed during this task (not gaps anymore)

- **phasync/babel** (`~/dev/phasync-babel`, commit `8934eda`): `includeHandler`'s rewrite of a
  `require`/`include` nested inside a ternary's own true-branch (`$cond ? require $f : 'none'`)
  absorbed the enclosing `:`, producing invalid PHP. Confirmed against real PHP that require's
  operand correctly stops at an *enclosing* ternary's `:` while still absorbing `.`, `??`,
  `or`/`and`, and a ternary it itself starts — `Unit::includeOperandEnd()` now tracks a `?`/`:`
  depth counter for exactly that case. New case `r6_includehandler_03`; full case suite (884) and
  the 127,010-file corpus (above) both green.
- **This integration** (`~/dev/swerve-plug`): `Plug::installIfNeeded()` initially required
  `$vendorDir/composer/installed.php` to exist, which broke `tests/AdapterTest.php`'s and
  `tests/IntegrationTest.php`'s own fixtures (a vendor directory that only forwards to the real
  one, e.g. Joomla's `libraries/vendor/autoload.php`) — fixed with a fallback to swerve's own
  install location, where phasync/plug and its dependencies are always real. `WorkerAutoloader.php`
  referenced `Plug` without loading it when required standalone (as
  `tests/UnitWorkerAutoloaderTest.php` does) — fixed with an explicit `require_once`.
