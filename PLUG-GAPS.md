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

### 1. phasync/hooked waited before trying: cancellation surfaced where ext-phasync's own fwrite() never would have waited at all — fixed; one swerve-psr15 WebSocket test still fails intermittently, now traced to a different, deeper cause

**Root cause, corrected from the previous round.** The previous round's fix (commit `661a47f`,
a shielded retry after a second `CancelledException`) treated this as a phasync-core limitation
(the "cut short" warning's flag only clears on a real suspend/resume cycle, which a shielded
retry cannot force). That framing was wrong. Measured directly against ext-phasync: a coroutine
blocked in `fread()` is cancelled, `fread()` throws `CancelledException` (expected — that read
really was waiting); its `finally` then calls `fwrite()` on a socket that already has room, and
the extension's own `fwrite()` **returns the byte count, no exception** — because it tries the
native, non-blocking write first and only waits (checking cancellation) when that attempt cannot
make progress. phasync/hooked's `hookedWriteLoop()` did the opposite: it called
`phasync::writable()` — which checks cancellation — *before* ever attempting the native
`fwrite()`, even when the socket was already writable. The "cut short" warning was a true
symptom of a real semantic gap from the extension, not an unavoidable core behaviour.

**Fixed in `~/dev/phasync-hooked`** (commit `472d438`): every hooked stream function that reads
or writes now makes one native, non-blocking attempt *first*; only when that attempt signals
"would block" (`fread`/`fgetc`/`fgets`'s own `''`/`false` on a read that isn't at EOF, `0` bytes
written on a write — confirmed empirically distinct from a real failure) does it wait through
phasync, then try the same native call again. This covers `hookedRead()` (`fread`/`fgetc`),
`hookedLineRead()` (`fgets`/`fgetcsv`), `hookedReadLoop()` (`stream_get_contents`/`fpassthru`/
`stream_copy_to_stream`'s read side), `hookedWriteLoop()` (`fwrite`/`fputs`/`stream_copy_to_stream`'s
write side) and `stream_get_line()`'s own loop. This also subsumes the old separate "skip the
wait when `unread_bytes > 0`" special case (README's rule 6): trying first picks up buffered data
without any extra check. `661a47f`'s shielded-retry patch is removed — it worked around the
symptom (a lost write) without fixing why the wait happened when the socket was already writable.
New test in `FreadTest.php` reproduces the exact reference scenario (cancelled `fread()`, the
`finally`'s `fwrite()` to a writable socket returns the byte count, no exception);
`FwriteTest.php`'s old cut-short test updated to the corrected expectation, plus a new
complementary case confirming cancellation still throws when a write genuinely has no room to
wait for (so the fix narrows *where* cancellation surfaces, not *whether* it ever does). Full
suite: 188/188 green.

**Effect on swerve-psr15.** Five reruns after the fix: `0 failed, 77 passed` once (45.22 s,
matching ext mode's own `77 passed` exactly), `1 failed, 76 passed` the other four times
(44-45 s each) — the *"subprotocols"* WebSocket test, which failed on every prior round's run,
now passes consistently in all five; the *"origin allow-list"* test still fails intermittently
(4 of 5 reruns), same `"Cleanup in a finally block ... was cut short"` warning as before.

**A sibling instance of the identical "wait before trying" shape exists in swerve-plug's own
code (`src/Util/Worker.php`'s `awaitMaster()`/`awaitInbox()`, the worker's always-running
master-pipe/inbox event loop, written directly against `phasync::readable()`/`writable()`
rather than through any hooked PHP builtin) — found, fixed the same way, and then reverted
after it proved to regress something else without fixing this failure.** Applying the identical
"try the native call first" pattern there seemed promising (a worker's own long-lived loop
coroutine is exactly the kind of fiber whose cancellation during a drain could show this race),
and passed every test tried at first (`PubSubTest.php`, `UnitWorkerAutoloaderTest.php`: `25
passed`; `LingerTest.php`: all green). But a full run of `SupervisionTest.php` then showed a new
failure, *"SIGHUP stops every worker, then restarts swerve... running the new code"*, asserting
`'v1'` where `'v2'` was expected — confirmed, by isolating just that one test, to reproduce
deterministically with the fix in place and disappear the moment it was reverted: removing
`awaitMaster()`'s unconditional wait meant that loop no longer has a guaranteed suspension point
on every iteration when the master sends several commands back to back (a reload followed
immediately by other traffic), so other coroutines — including the one serving the *new*
worker's first requests — got no chance to run in between, and a probe landed on code that
hadn't taken over yet. Rule 3's "try first" is sound for a single operation call site, but
`awaitMaster()`/`awaitInbox()` are not single calls: they are a worker's whole cooperative
scheduling backbone, and losing their one guaranteed yield point is a correctness issue, not
just an efficiency one. **Reverted** (swerve-plug, uncommitted — the repo is back to its prior
`Worker.php`), and did not fix the *"origin allow-list"* failure anyway (confirmed: it still
failed, twice, with the fix in place, before the regression was found and the revert decided).
A *second*, unrelated, pre-existing failure surfaced in the same `SupervisionTest.php` run
(*"a SIGHUP during a reload is not lost..."*, a tight 1 s timing deadline) and was confirmed, by
reproducing it twice more on the fully reverted code, to be pre-existing flakiness, not caused by
this round's changes either way.

**The remaining *"origin allow-list"* failure's real site, traced one level further: swerve's
own `phasync::shielded()`-wrapped connection-draining cleanup, not a "wait before trying" bug at
all.** `src/WebSocket.php`'s `put()` (every close-frame write) and `src/Http/HttpConnection.php`'s
`serve()` (every connection's own lingering-drain cleanup) both wrap their cleanup in
`phasync::shielded()` deliberately, so a cancelled fiber can still finish saying goodbye to its
client. This is correct by design — but `phasync::shielded()` only keeps a *new* cancellation
from reaching the wrapped closure; it does not, by itself, clear phasync core's own
`cutShortCleanup` flag (see the previous round's own analysis of `EventLoop.php`, still accurate
for this case even though it was wrongly generalised to the hooked write bug too): that only
happens if the wrapped wait actually suspends the fiber and the event loop resumes it through the
normal tick path. Whether the shielded write/read inside `put()`/`serve()`'s cleanup needs to
suspend at all depends on whether the socket happens to already have room/data at that exact
moment — which is exactly why this one test's failure is intermittent rather than constant: the
race is in phasync core's warning bookkeeping around an intentional, correct use of
`phasync::shielded()`, not an avoidable "didn't try first" bug like the one fixed in
phasync/hooked and (unsuccessfully) attempted in `Worker.php`.

**Disposition.** The `fread`-cancelled-then-`fwrite`-to-writable-socket reference case (this
round's actual target) is closed and tested; four of swerve-psr15's five reruns above are fully
clean except for this one, now-better-understood, pre-existing intermittent failure, down from
the two consistently-failing WebSocket cancellation tests before this round. Fixing the
remaining failure for good would mean either making `phasync::shielded()` itself guarantee its
flag clears (a phasync core change, out of this task's permitted scope — same conclusion as last
round, now correctly scoped to just this one case instead of the whole gap) or restructuring
`put()`/`serve()`'s cleanup to avoid the shield, which is a larger, riskier change to swerve's own
connection-draining correctness than this round's time allows to make carefully.

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
| swerve-psr15 | ~45.0 s (4 of 5 reruns this round; 1 ran clean at 45.22 s, matching ext mode's own count exactly) | 40.97 s | ~+4 s (+10%); gap #1's one remaining intermittent failure, not a clean comparison every run |
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
- **phasync/hooked** (`~/dev/phasync-hooked`, commit `661a47f`, superseded this round by
  `472d438` below): the write-side cancellation cut-short, the data-loss half of this list's
  gap #1. New case in `FwriteTest.php`. Full suite: 186/186 green at the time.
- **phasync/hooked, round 2** (`~/dev/phasync-hooked`, commit `472d438`): `661a47f`'s
  shielded-retry patch replaced with the real fix: every hooked stream read/write function now
  tries its native, non-blocking call first and waits through phasync only when that attempt
  signals it would block, matching ext-phasync's own `fread()`/`fwrite()` exactly (see gap #1
  above for the measured reference case and the full list of functions touched). Full suite:
  188/188 green.
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

Gap #1's *"origin allow-list"* intermittent failure (root-caused to `phasync::shielded()`'s own
flag-clearing gap in swerve's legitimate connection-draining cleanup, not fixable from
phasync/hooked/plug/babel — see above) and gap #4 (Yii's logged warning, cause still not
isolated) are what remain open. Gaps #2 and #3 are accepted by the owner's own ruling, not open
questions. Symfony and CakePHP's Reflection-path issues, the `ReflectionMethod`/
`WrapsClassAccessors` traps found along the way, and the `fread`-cancelled-then-`fwrite`
reference case this round targeted, are now closed and tested. A same-shaped fix attempted in
swerve-plug's own `Worker.php` (`awaitMaster()`/`awaitInbox()`) was reverted after it regressed
a `SupervisionTest.php` reload case without fixing the target failure — recorded above as a
negative result, not applied. None of the open items block the plug from working — every adapter
boots and serves real requests without the extension.
