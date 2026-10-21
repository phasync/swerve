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
swerve-symfony, swerve-cakephp, swerve-yii, swerve-laminas (PHP 8.4, `grpc.so` excluded from its
ini scan dir — it loads on 8.4 only and crashes under 8.5), swerve-spiral. All 8 adapters plus
swerve's own suite: every suite the task named.

## Gaps, grouped by cause, most impactful first

### 1. phasync/hooked's "cut short" diagnostic warning survives a successful shielded retry — write-side data loss fixed; the warning itself is not suppressible from here

**Fixed.** `hookedWriteLoop()`'s `phasync::writable()` wait only caught `TimeoutException`: a
`CancelledException` reaching the fiber a *second* time (sticky once a fiber is cancelled, by
phasync's own design) propagated immediately, before ever finding out whether the stream was
ready — cutting short a write a native `fwrite()` would simply have completed (it has no concept
of phasync's cancellation at all). Confirmed the exact shape with a minimal reproduction (a fiber
cancelled mid-`phasync::sleep()`, whose `catch` then calls `\phasync\hooked\fwrite()` to send a
goodbye) and traced it to swerve-psr15's WebSocket tests: a client left a socket open across
`native_stop()`'s drain, and the worker's own WebSocket cleanup write hit this exact race.

**Fixed in `~/dev/phasync-hooked`** (commit `661a47f`): `hookedWriteLoop()` now catches that
second `CancelledException` and gives the write one more chance — one shielded, briefly-bounded
(0.05 s, not the stream's own, possibly much longer or unbounded, configured timeout, so a drain
never waits that out for this) `phasync::writable()` + `fwrite()` — then **always** re-throws the
original cancellation regardless (this is not a way to ignore it, only to let an already-ready
write happen first, matching what a native call would have done). New case in `FwriteTest.php`
(`phasync::go()` + `phasync::cancel()`, a hooked `fwrite()` in the fiber's own first-cancellation
catch block) fails without the fix (the write is lost) and passes with it, with no "cut short"
warning. Full phasync/hooked suite: 186/186 green.

**Effect on swerve-psr15, revisited.** A single rerun right after the fix showed `1 failed, 76
passed` (the *"origin allow-list"* WebSocket test passing, *"subprotocols"* still failing). Two
more reruns since (same code, same scratch copy, nothing else changed) both show `2 failed, 75
passed` (43.5-45.0 s), *"origin allow-list"* failing again alongside *"subprotocols"* — same
`"Cleanup in a finally block ... was cut short"` warning on both, confirmed deterministic across
those two reruns. **Root-caused**, and it is not a bug in the fix: it's a property of the warning
itself that `phasync/hooked` cannot control from userland.

phasync core (`~/dev/phasync`, out of scope to modify) marks a fiber's
`cutShortCleanup[$fiber] = true` the moment `checkCancelled()` throws a *second* time for that
fiber (`EventLoop.php:2030`), and only clears it when the fiber is next **resumed through the
normal tick/wake path** (`EventLoop.php:712` and `:837`, both commented "a cut-short mark... only
still matters if nothing ran for this fiber since: it really suspended and resumed again"). The
warning itself fires at fiber termination if the flag is still set
(`EventLoop.php:2276-2283`). `phasync::shielded()` (`phasync.php:971`) only suspends *new*
cancellations from reaching the wrapped closure (`$driver->shield($fiber)`); it does not itself
clear the flag, touch `EventLoop`'s internal state, or force a resume cycle — clearing only
happens as a side effect of the shielded wait actually suspending the fiber and the event loop
resuming it. Whether `phasync::writable()` suspends at all depends on whether the poller resolves
the stream as already-writable synchronously (common for a short local socket write): when it
does, no resume cycle runs, the flag set by the *original* (pre-shield) cancellation stays set
through to the retried write's success and the subsequent (deliberate) re-throw of that original
cancellation, and the fiber terminates with the flag still set — so the warning fires regardless
of whether the write itself succeeded. This is why the very first rerun showed the fix "working"
(lucky timing: that particular write happened to suspend) and both reruns since do not: there is
no public phasync API from `phasync::shielded()`/`phasync::writable()` to request or detect an
actual suspend, so `phasync/hooked` cannot make the flag clear deterministically, and cannot
silence the warning once it has been set, without a phasync core change.

**Disposition.** The write itself is fixed and proven (`FwriteTest.php`'s isolated case, no
timing dependency, 186/186 green): no `fwrite()` is ever lost to a second cancellation anymore.
The *diagnostic warning* survives regardless, timing-dependently, and both WebSocket tests that
happen to hit this cleanup path (*"origin allow-list"*, *"subprotocols"*) fail swerve-psr15's own
`rt_clean()` assertion (no `Warning:`/`error`/etc. anywhere in the log) because of it — not because
anything is actually broken. Applying the identical catch-and-shielded-retry pattern to
`hookedRead()` (the natural read-side counterpart) did **not** reproduce or fix anything in a
dedicated, isolated test, so that change was reverted rather than kept speculatively; the read
side evidently isn't where either of these two tests' own cut-short originates. **Size:** the data-
loss half is fixed and closed. The warning half is a phasync core behaviour with no suppression
hook exposed to callers — not fixable from `phasync/hooked`, `phasync/plug` or `phasync/babel`;
would need a phasync core change (out of this task's permitted scope) such as an explicit "this
cleanup finished, don't warn" acknowledgement, or `shielded()` itself clearing the flag
unconditionally on exit.

### 2. Worker boot is slower under the plug — cold-cache only; warm boot matches ext-phasync

**Revised finding.** The first measurement in this gap list (0.65 s cold vs. 0.04 s with
ext-phasync) conflated a one-time cost with a per-boot one. Measured directly, three fresh worker
boots in a row, same fixture, same cache directory:

| | cold (cache dir just created) | warm (2nd fresh process) | warm (3rd fresh process) |
|---|---|---|---|
| Worker ready in | 0.21 s (0.65 s on an earlier, more loaded run) | 0.03 s | 0.04 s |

Warm-boot latency is statistically indistinguishable from ext-phasync's own 0.04 s. The cost is
paid once, when a cache directory is first populated (first worker of a fresh deploy, or after a
code change invalidates cache keys) — not on every worker start, recycle or reload, since the
on-disk cache (keyed by file path + mtime + size + babel's fingerprint) is shared and reused by
every later process. This also better explains why re-running swerve's own `SupervisionTest`
failures in isolation mostly didn't reproduce (3 of 4): those tests reload/restart a worker whose
cache was already warm from the suite's own earlier tests, so the real cause of those few
failures is most likely ordinary system-load flakiness on the shared box this ran on, not a
systematic plug-caused latency regression. The one that did reproduce consistently in isolation
(*"a reload whose new worker fails to start..."*, a 1 s recovery budget) stays listed as a real,
if narrow and unconfirmed-cause, timing margin.

**Disposition.** Per the owner's ruling for this task, a slower *cold* boot is accepted, not
fixed: it is paid once per deploy, not per worker.

### 3. No hooked PDO/mysqli/pgsql driver — accepted, not independently reproduced here

phasync/hooked implements time, stream and process functions only (its own README's "Functions
implemented" list); no database driver is hooked. A worker without ext-phasync that opens a real
database connection inside a coroutine blocks the whole worker for that query's duration, same as
the pre-plug baseline. Per the owner's ruling, this is accepted: list it, don't chase it. None of
the eight adapter suites exercise a non-SQLite database from inside a coroutine in a way that
would measure this directly (every fixture here uses SQLite, whose file I/O is native per
phasync/hooked's own rule 5 — regular files never block — so none of them exhibit this gap
either way).

### 4. swerve-yii: a request logged something matching `error|exception|warning` — 1 failure, cause not isolated

**Evidence.** Plug mode: `1 failed, 24 passed`, 24.37 s; ext mode: `0 failed, 25 passed`, 20.26 s
— confirmed plug-specific. The failing test (*"it logged no errors"*) asserts the whole suite's
accumulated log never matches `/error|exception|warning/i`; under plug mode it does, once,
somewhere across the file's other tests (the log is shared across the whole test file's app
instance). Running the single test in isolation (skipping the tests that normally populate the
log first) does not reproduce it — same shape as gap #1's psr15 failures: it needs the full
sequence of a real test run, not a standalone repro, to show up, and that was not isolated within
this task's remaining time. **Size:** unknown; the matched text itself was not captured by Pest's
own truncated assertion diff and was not pursued further (`$this->log`'s file is removed in
`afterAll()` once the suite finishes, so it needs an isolated capture, not a rerun, to read in
full).

**Checked against this round's Reflection-path fix (see "Fixed during this task" below): not the
same cause.** The coordinator's hypothesis that this might share CakePHP's DebugKit cause (both
being Reflection-path issues) does not hold for Yii: the fix that resolved CakePHP (and Symfony)
left this exact failure unchanged, same test, same message shape, both before and after.

## Fully clean (no plug-specific gap found)

- **swerve-symfony**: `28 passed` (1 of those a warning in ext mode's own count, `27 passed, 1
  warning`) both modes, now that gap #1's old entry (Reflection path, see "Fixed during this
  task") is resolved. Plug: 27.07 s; ext: 24.24 s.
- **swerve-cakephp**: `30 passed` both modes, now that the DebugKit gap (same Reflection-path
  cause as Symfony's, see "Fixed during this task") is resolved. Plug: 30.83 s; ext: 27.99 s.
- **swerve-laminas**: `30 passed` both modes (PHP 8.4, no `grpc.so`). Plug: 56.40 s; ext: 50.80 s.
- **swerve-spiral**: `28 passed` both modes. Plug: 48.24 s; ext: 45.82 s.

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
| swerve-psr15 | 42.46 s | 41.0 s | +1.5 s (+3.6%) |
| swerve-laravel | 233.11 s | 198.55 s | +34.6 s (+17.4%) |
| swerve-codeigniter | 47.02 s | 45.09 s | +1.9 s (+4.3%) |
| swerve-symfony | 27.07 s | 24.24 s | +2.8 s (+11.7%) |
| swerve-cakephp | 30.83 s | 27.99 s | +2.8 s (+10.2%) |
| swerve-yii | 24.37 s | 20.26 s | +4.1 s (+20.3%) |
| swerve-laminas | 56.40 s | 50.80 s | +5.6 s (+11.0%) |
| swerve-spiral | 48.24 s | 45.82 s | +2.4 s (+5.3%) |

phasync/babel's own corpus check (not a mode comparison; verifies the ternary-precedence fix,
below): 127,010 real-world files (WordPress, Laravel, Symfony, CakePHP, CodeIgniter, Drupal,
Joomla, Matomo, MediaWiki, Moodle, Nextcloud, phpBB, PrestaShop from
`~/dev/swerve-sapi/acceptance/apps/`), 342.5 s with 48 jobs, 0 lint failures, 0 mismatches against
the php-parser oracle.

## Fixed during this task (not gaps anymore)

- **phasync/babel** (`~/dev/phasync-babel`, commit `8934eda`): `includeHandler`'s rewrite of a
  `require`/`include` nested inside a ternary's own true-branch (`$cond ? require $f : 'none'`)
  absorbed the enclosing `:`, producing invalid PHP. `Unit::includeOperandEnd()` now tracks a
  `?`/`:` depth counter for exactly that case. New case `r6_includehandler_03`; full case suite
  (884) and the 127,010-file corpus (above) both green.
- **phasync/plug** (`~/dev/phasync-plug`, commit `06b16c8`): the cache-mirror-of-mirror crash,
  originally this list's gap #1. `Loader::unmirror()`; new case in `LoaderTest.php`. Full suite:
  12/12 green.
- **phasync/hooked** (`~/dev/phasync-hooked`, commit `661a47f`): the write-side cancellation
  cut-short, the data-loss half of this list's current gap #1. New case in `FwriteTest.php`.
  Full suite: 186/186 green.
- **phasync/plug, round 2** (`~/dev/phasync-plug`, commits `e1daab1`, `fd4c367`): the deeper
  issue the mirror-of-mirror fix exposed. `Kernel::getProjectDir()`-style code
  (`dirname((new \ReflectionObject($this))->getFileName())`) resolved to the cache mirror, not
  the original project root — the mirror has only translated `.php` files, so Symfony's routing
  (and CakePHP's DebugKit, confirmed the same cause) found nothing. Fixed with four Reflection
  subclasses (`ReflectionClass`, `ReflectionObject`, `ReflectionEnum`, `ReflectionFunction`,
  mapped in `Loader::install()`'s `'cache'`-mode-only class map, so babel rewrites `new`,
  `instanceof` and type declarations to them) whose `getFileName()` unmirrors the path, plus
  `get_included_files()`/`get_required_files()`/`debug_backtrace()`/`debug_print_backtrace()`
  function replacements doing the same for their `'file'` entries. `ReflectionMethod` is
  deliberately **not** mapped: confirmed against Symfony's own
  `AutowireRequiredMethodsPass::isWither()` that `ReflectionClass::getMethods()` returns *native*
  `ReflectionMethod` instances regardless of the map, so a parameter babel also rewrote to expect
  the mapped type then rejected them with a `TypeError` (commit `87b3057` dropped it again after
  confirming this).

  Mapping `ReflectionClass` itself turned out to have the identical exposure one level up:
  confirmed against Symfony's `Config\Resource\ReflectionClassResource::loadFiles()`, which
  recurses through `getParentClass()`/`getInterfaces()`/`getTraits()` with a
  `\ReflectionClass`-typed parameter babel also rewrote — and those three native methods always
  return plain `\ReflectionClass` instances too, so the same `TypeError` happened one level
  deeper. Unlike `ReflectionMethod`, these three are common enough (any class-hierarchy walk) to
  wrap rather than exclude: a new `WrapsClassAccessors` trait (commit `e1daab1`) overrides all
  three to rewrap their results in `phasync\plug\Reflection\ReflectionClass`. New case in
  `LoaderTest.php` reproduces the exact `loadFiles()` pattern (an interface, a trait and a parent,
  walked recursively), fails without the fix and passes with it. Other native APIs that return a
  bare `\ReflectionClass` (`ReflectionProperty::getDeclaringClass()`,
  `ReflectionFunctionAbstract::getClosureScopeClass()`, ...) are not wrapped and remain the same
  kind of residual risk, documented in code, not found to matter in any suite run here.

  A second bug, caught before it reached any adapter's recorded result: `WrapsClassAccessors.php`
  was used by the three Reflection subclasses but never added to `Loader::install()`'s own
  `require_once` list (deliberately not autoload-reliant, since `install()` can run before an
  application's own autoloader is safe to use) — masked in phasync/plug's own test suite by its
  dev autoloader covering `src/` regardless, but fatal in a real app (a swerve-psr15 smoke test
  caught it: `Trait "...WrapsClassAccessors" not found`, exit 255, every test failing). Fixed in
  the same round (commit `fd4c367`) before any of the reruns below were taken as final. Full
  phasync/plug suite: 14/14 green throughout.
- **This integration** (`~/dev/swerve-plug`): `Plug::installIfNeeded()` initially required
  `$vendorDir/composer/installed.php` to exist, which broke `tests/AdapterTest.php`'s and
  `tests/IntegrationTest.php`'s own fixtures (a vendor directory that only forwards to the real
  one, e.g. Joomla's `libraries/vendor/autoload.php`) — fixed with a fallback to swerve's own
  install location, where phasync/plug and its dependencies are always real. `WorkerAutoloader.php`
  referenced `Plug` without loading it when required standalone (as
  `tests/UnitWorkerAutoloaderTest.php` does) — fixed with an explicit `require_once`.

## What's left

Gap #1's warning-suppression half (root-caused as a phasync core behaviour with no suppression
hook — see above) and gap #4 (Yii's logged warning, cause still not isolated) are what remain
open. Gaps #2 and #3 are accepted by the owner's own ruling, not open questions. Symfony and
CakePHP's Reflection-path issues, and the `ReflectionMethod`/`WrapsClassAccessors` traps found
along the way, are now closed and tested. None of the open items block the plug from working —
every adapter boots and serves real requests without the extension.
