<?php

namespace Swerve\Util;

use phasync\Util\LruCache;
use Psr\Log\LoggerInterface;
use Swerve\Cache;

/**
 * The master process: starts the worker-parent (a `php -d phasync.workers=N` process, the ext's
 * C parent) and supervises it and the lifecycles it runs until stopped. The master itself never
 * loads the application and is not a phasync process: a plain stream_select() loop, so a bug in
 * the application, or an event loop stall, can't take the master down.
 *
 * Every lifecycle (see inc/lifecycle.php) connects to the master's own unix socket (its path is
 * SWERVE_MASTER) when it starts, and says hello: its slot (PHASYNC_WORKER) and pid. The master
 * answers with an inbox number (see Inboxes) and a start delay (the failed-start backoff: the
 * policy stays here, so the lifecycle sleeps before loading the application). From then on the
 * connection carries the same bytes as before 0.2's worker-parent model: '.' a heartbeat four
 * times a second, 'R' once a lifecycle is ready to accept(), 'Q' at its first request, 'C' to ask
 * to be recycled, 'D' when it drains; the end of the connection is the lifecycle's end (not
 * necessarily the process's: the ext may run another lifecycle in the same process next). The
 * master writes 'T' to drain, 'G' to drain but linger (an upgraded connection's recycle), 'X' at
 * shutdown and 'L' to reopen the log file; cache and subscription requests (see Cache, Inboxes)
 * go over it too, framed as Topics::frame() says. A connection silent longer than the watchdog
 * timeout is stuck: its pid gets SIGQUIT, to log what it is stuck in, then SIGKILL.
 *
 * Recycling (--max-memory, --max-requests) needs no master involvement when a lifecycle has no
 * upgraded connections to keep: it simply stops calling accept(), its lifecycle ends, and the ext
 * runs the next one in the same process, fresh. With upgraded connections, the lifecycle calls
 * phasync\ext\worker_detach() itself (the ext forks a replacement for its index at once) and then
 * lingers up to --linger seconds before it finishes. The master only logs 'C' and relies on the
 * ext's replacement and the lifecycle's own linger timer; see Worker.
 *
 * A reload (SIGHUP or SIGUSR2) starts a second worker-parent, of the next generation, on the
 * current code (a fresh `php` process: a fresh opcache compile — the shared opcache of a
 * long-lived worker-parent, with `opcache.validate_timestamps=0`, would otherwise go on serving a
 * changed file's old bytecode forever), inheriting the same listener and inbox descriptors, so
 * both can accept() meanwhile ("the lot keeps accepting"). Once every slot of the new generation
 * is ready, every lifecycle of the old generation is told to drain ('T') together, and the old
 * worker-parent itself gets SIGTERM: on the byte protocol its lifecycles unwind and exit
 * promptly, and the SIGTERM is what stops the ext from running another one in a slot that just
 * did (see tests/worker_sigterm_graceful.phpt) — without it a drained lifecycle would simply
 * reconnect and start over. The old worker-parent is reaped once every slot is gone that way, or
 * SIGKILLed after the grace period (its lifecycles die with it: the extension's PR_SET_PDEATHSIG). A new generation's
 * worker-parent that dies before any of its
 * slots is ready leaves the old one serving untouched, and is retried with backoff (the same
 * policy as a failed lifecycle start). Signals: SIGTERM, SIGINT or SIGQUIT drain every lifecycle
 * within the grace period, SIGKILL what remains, and stop the worker-parent the same way. SIGUSR1
 * only reopens the log file.
 *
 * The master reaps every child that ends, also those it did not start itself: as PID 1 in a
 * container, or a subreaper, it inherits the orphans of the application's background jobs.
 *
 * Times are from the monotonic clock, which a change of the system time doesn't move.
 *
 * @internal
 */
final class Cluster
{
    /** Longest stream_select() wait: bounds how late any deadline is handled. */
    public const TICK = 0.25;
    /** Shortest round of the master's loop after one that only read heartbeats; see Worker::TICK. */
    public const ROUND_MIN = 0.02;

    /** What Swerve::cache() holds, for every worker: see Cache. */
    private LruCache $cache;
    /** The workers' inboxes, and who subscribes to what. */
    private Inboxes $inboxes;
    /** No inbox was free for a new hello: logged once, until one is. */
    private bool $noInbox = false;
    /** Application load: a lifecycle not ready within this is killed as a failed start. */
    public const READY_TIMEOUT = 60.0;
    /** Ready this long, or having served: not part of a crash loop, and resets the slot's failures. */
    public const STABLE_AFTER = 5.0;
    public const BACKOFF_FIRST = 0.5;
    public const BACKOFF_MAX = 30.0;
    /** Consecutive failed starts from which the log is critical. */
    public const CRASH_LOOP = 3;
    /** How long a stuck lifecycle gets to log what it is stuck in, after SIGQUIT and before SIGKILL. */
    public const REPORT_WAIT = 0.1;
    /** After SIGKILL, the longest wait for the processes to be reaped before the master exits anyway. */
    public const KILL_WAIT = 5.0;
    public const MONITOR_EVERY = 1.0;
    public const MONITOR_SHARE = 10;
    public const PAUSED = 1.0;
    /** Lingering lifecycles a slot may have at once (see Inboxes: four inboxes per slot). */
    public const MAX_LINGERING = 3;
    /** Descriptor number the first listener (if any) is passed at; the rest follow it, one each. */
    public const LISTENER_FD = 3;

    /** @var array<int, LifecycleConnection> by an id of our own (hello order), not the pid */
    private array $connections = [];
    /** @var array<int, resource> accepted, hello not complete yet, by the same id space */
    private array $pending = [];
    /** @var array<int, string> partial hello bytes, by the same id */
    private array $pendingIn = [];
    private int $nextId = 1;

    /** @var array<int, int> consecutive failed starts per slot, current generation */
    private array $failures = [];
    private int $generation = 0;
    private bool $everReady = false;
    private ?int $exitCode = null;
    private int $stopSignals = 0;
    private ?string $reloadSignal = null;
    private bool $reopenSignal = false;
    private float $nextScan = 0;
    private float $lastDeadlines = 0;
    private float $lastRead = 0;
    private bool $onlyHeartbeats = false;
    /** @var array<string, string>|null files and their mtime:size the workers run */
    private ?array $scanSeen = null;
    private ?array $scanPending = null;
    private readonly int $masterPid;

    /** @var array<int, array{pid: int, process: resource, started: float, stopping: bool}> by generation */
    private array $workerParents = [];
    /** Consecutive failures starting the current generation's worker-parent during a reload (the old one keeps serving meanwhile); reset once a lifecycle of it readies. */
    private int $workerParentFailures = 0;
    /** Death reports the worker-parent made on its stderr (pid => "exit 7" / "signal 9 (SIGKILL)")
     * whose lifecycle connection has not ended yet; ended() logs them. */
    private array $pendingDeaths = [];
    /** @var array<int, string> The last reported death cause per slot (for crash-loop lines). */
    private array $lastHow = [];
    /** @var array<int, array{int, string, bool}> slot => [pid, why, lingering]: drain completed, its takeover not yet logged. */
    private array $pendingTakeovers = [];
    /** @var array<int, array{int, int, string, bool}> pid => [pid, slot, why, lingering]: draining, for noteCleanDrain(). */
    private array $drainInfo = [];
    /** pid => [slot, up, killReason] of connections that just ended: the worker's exit closes its
     * socket before the worker-parent's reap reports the death (up to one lot tick later), so the
     * report usually arrives second; deathReport() logs the died line from these. */
    private array $recentlyEnded = [];
    /** When to retry starting the current generation's worker-parent after such a failure; 0 = no retry pending. */
    private float $workerParentRetryAt = 0;
    /**
     * Slots of a stopping generation that drained at least once; @var array<int, array<int, true>> by generation.
     * A phasync::run() lifecycle defeats the ext's own "do not replace this slot" bookkeeping for
     * a worker-parent that got SIGTERM directly (reproduced, not yet understood; filed upstream):
     * it keeps re-running the slot instead of letting the process exit. So instead of waiting for
     * that process to exit on its own, its process group is SIGKILLed outright once every one of
     * its slots is known to have drained once — no in-flight request is lost (a drained slot has
     * none), and whatever the ext reran in it afterwards never held a real connection for long.
     */
    private array $drainedOnce = [];

    /** @var resource the master's own unix socket, non-blocking */
    private $masterListener;
    private readonly string $masterSocketPath;

    /**
     * @param float                 $grace       seconds a draining lifecycle gets before SIGKILL
     * @param float                 $linger      seconds a recycled lifecycle may keep its upgraded connections; 0 = not at all
     * @param float                 $watchdog    seconds of silence after which a lifecycle is killed; 0 = off
     * @param string|null           $monitorDir  reload when a PHP file below it changes
     * @param string                $serving     what is served where, logged once the first lifecycle is ready
     * @param list<string>          $workerParentArgv the worker-parent's command line (php binary and every argument);
     *                                                 when $listeners is not empty, a `-d phasync.workers_listener=` comma list of
     *                                                 self::LISTENER_FD, self::LISTENER_FD + 1, ... (one per listener, in the same
     *                                                 order) is in it already, `-d phasync.workers_listener=-1` otherwise
     * @param array<string, string> $workerParentEnv  env common to every generation (SWERVE_MASTER* and the inbox fd layout are added here)
     * @param list<resource>        $listeners        the HTTP listeners, at descriptors self::LISTENER_FD, +1, ... in every
     *                                                 generation, in the order $workerParentArgv's ini list names them; empty for --no-http
     */
    public function __construct(
        private readonly int $numWorkers,
        private readonly LoggerInterface $logger,
        private readonly float $grace,
        private readonly float $linger,
        private readonly float $watchdog,
        private readonly ?string $monitorDir,
        private readonly string $serving,
        private readonly array $workerParentArgv,
        private readonly array $workerParentEnv,
        private readonly array $listeners = [],
        int $cacheBytes = 64 << 20,
    ) {
        $this->cache = new LruCache(maxBytes: $cacheBytes);
        // A serving lifecycle, its replacement starting, and up to MAX_LINGERING lingering ones per slot
        $this->inboxes = new Inboxes((1 + self::MAX_LINGERING) * $numWorkers);
        $this->masterPid = \posix_getpid();
        OrderedLog::directory();
        $this->logger->info('Master process {pid}, {n} workers', ['pid' => $this->masterPid, 'n' => $numWorkers]);
        if ('0' === \trim((string) @\file_get_contents('/proc/sys/net/ipv4/tcp_migrate_req'))) {
            $this->logger->info('net.ipv4.tcp_migrate_req=0: connections queued on a closing listener are reset during reload/recycle; set it to 1 for lossless handovers');
        }
        $this->masterSocketPath = System::tempDirectory('swerve-master') . '/master.sock';
        $this->masterListener   = System::listenControl($this->masterSocketPath);
    }

    /**
     * Supervise until stopped, and return the exit code.
     */
    public function run(): int
    {
        \pcntl_signal(\SIGTERM, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGINT, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGQUIT, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGHUP, function () { $this->reloadSignal = 'SIGHUP'; });
        \pcntl_signal(\SIGUSR2, function () { $this->reloadSignal = 'SIGUSR2'; });
        \pcntl_signal(\SIGUSR1, function () { $this->reopenSignal = true; });
        \pcntl_async_signals(true);
        for ($slot = 0; $slot < $this->numWorkers; ++$slot) {
            $this->failures[$slot] = 0;
        }
        if (null !== $this->monitorDir) {
            $this->scanSeen = $this->snapshot();
            $this->logger->info('Watching {dir} for changes', ['dir' => $this->monitorDir]);
        }

        $this->startWorkerParent();

        while (true) {
            $this->acceptPending();
            $this->readPipes(self::TICK);
            $this->reap();
            if (null !== $this->exitCode) {
                return $this->stopAll($this->exitCode);
            }
            if ($this->stopSignals) {
                return $this->stopAll(0);
            }
            if (null !== $this->reloadSignal) {
                $this->reload($this->reloadSignal);
                $this->reloadSignal = null;
            }
            if ($this->workerParentRetryAt > 0 && self::now() >= $this->workerParentRetryAt) {
                $this->workerParentRetryAt = 0;
                $this->startWorkerParent();
            }
            if ($this->reopenSignal) {
                $this->reopenSignal = false;
                $this->reopenLog();
                $this->logger->notice('Reopened the log file (SIGUSR1)');
            }
            $this->deadlines();
            if (null !== $this->monitorDir && self::now() >= $this->nextScan) {
                $start = self::now();
                $file  = $this->changedFile();
                $now   = self::now();
                $this->nextScan      = $now + \max(self::MONITOR_EVERY, self::MONITOR_SHARE * ($now - $start));
                $this->lastDeadlines = $now;
                if (null !== $file) {
                    $this->logger->notice('Change detected in {file}; reloading', ['file' => $file]);
                    $this->reload("changed: $file");
                }
            }
        }
    }

    /**
     * proc_open the worker-parent of the current (or, for a reload, the next) generation. It
     * makes itself a process group of one in this session (the extension, before it forks),
     * so signalling the group never reaches it by accident and signalling it never reaches the
     * master. Its lifecycles join the master's group themselves (the extension's fork), and
     * die with it (PR_SET_PDEATHSIG).
     */
    private function startWorkerParent(): void
    {
        $generation = $this->generation;
        [$reads, $writes] = $this->inboxes->streams();
        $fd         = self::LISTENER_FD + \count($this->listeners);
        $inboxBase  = $fd;
        $env        = $this->workerParentEnv + [
            'SWERVE_MASTER'        => $this->masterSocketPath,
            'SWERVE_MASTER_PID'    => (string) $this->masterPid,
            'SWERVE_GENERATION'    => (string) $generation,
            'SWERVE_INBOX_COUNT'   => (string) \count($reads),
            'SWERVE_INBOX_FD_BASE' => (string) $inboxBase,
            // The ordered log's directory is the master's (it removes it): without this every
            // lifecycle made its own, and an ordered channel worked per worker, not per topic.
            'SWERVE_ORDERED_DIR'   => OrderedLog::directory(),
        ];
        // stdout goes to the master's own (a closed-pipe read end would SIGPIPE it on a stray
        // echo). stderr is a pipe the master reads: the worker-parent reports every abnormal
        // worker death on it (see deathReport()), and PHP's or the app's own stderr text
        // (warnings, the engine's fatal message) belongs in the log too. Nothing reads stdin.
        $descriptors = [0 => ['pipe', 'r'], 1 => \STDOUT, 2 => ['pipe', 'w']];
        foreach (\array_values($this->listeners) as $i => $listener) {
            $descriptors[self::LISTENER_FD + $i] = $listener;
        }
        foreach ($reads as $stream) {
            $descriptors[$fd++] = $stream;
        }
        foreach ($writes as $stream) {
            $descriptors[$fd++] = $stream;
        }
        $process = \proc_open($this->workerParentArgv, $descriptors, $pipes, null, $env);
        if (false === $process) {
            throw new \RuntimeException('Could not start the worker-parent process');
        }
        \fclose($pipes[0]);
        \stream_set_blocking($pipes[2], false);
        $pid = \proc_get_status($process)['pid'];
        $this->workerParents[$generation] = ['pid' => $pid, 'process' => $process, 'started' => self::now(), 'stopping' => false, 'stderr' => $pipes[2], 'stderrBuf' => ''];
        $this->logger->info('Started the worker-parent {pid} (generation {generation})', ['pid' => $pid, 'generation' => $generation]);
    }

    /**
     * Accept new connections on the master socket: not hello yet, so not a LifecycleConnection.
     */
    private function acceptPending(): void
    {
        while (false !== $conn = @\stream_socket_accept($this->masterListener, 0)) {
            \stream_set_blocking($conn, false);
            $id                    = $this->nextId++;
            $this->pending[$id]    = $conn;
            $this->pendingIn[$id]  = '';
        }
        \error_clear_last();
    }

    /**
     * Wait up to $timeout for bytes from pending hellos and existing connections, and act on them.
     */
    private function readPipes(float $timeout): void
    {
        $sooner = $this->lastRead + self::ROUND_MIN - self::now();
        if ($this->onlyHeartbeats && $sooner > 0) {
            \usleep((int) ($sooner * 1_000_000));
        }
        $this->lastRead       = self::now();
        $this->onlyHeartbeats = false;
        $read = [$this->masterListener];
        foreach ($this->pending as $id => $conn) {
            $read["p$id"] = $conn;
        }
        foreach ($this->connections as $id => $w) {
            if ($w->pipe) {
                $read["c$id"] = $w->pipe;
            }
        }
        foreach ($this->workerParents as $generation => $info) {
            if (isset($info['stderr'])) {
                $read["s$generation"] = $info['stderr'];
            }
        }
        $write = [];
        foreach ($this->connections as $id => $w) {
            if ($w->pipe && '' !== $w->out) {
                $write["c$id"] = $w->pipe;
            }
        }
        $except = null;
        if (!@\stream_select($read, $write, $except, 0, (int) ($timeout * 1_000_000))) {
            return;
        }
        foreach ($write as $key => $pipe) {
            $this->flush($this->connections[(int) \substr($key, 1)]);
        }
        $now                  = self::now();
        $this->onlyHeartbeats = true;
        foreach ($read as $key => $stream) {
            if ($stream === $this->masterListener) {
                $this->onlyHeartbeats = false;
                continue; // acceptPending() handles it next round
            }
            $id = (int) \substr($key, 1);
            if ('p' === $key[0]) {
                $this->readHello($id, $now);
                continue;
            }
            if ('s' === $key[0]) {
                $this->readStderr($id);
                continue;
            }
            $this->readStatus($this->connections[$id], $now);
        }
    }

    /** More bytes of a worker-parent's stderr: death reports for deathReport(), the rest is logged. */
    private function readStderr(int $generation): void
    {
        if (!isset($this->workerParents[$generation]['stderr'])) {
            return;
        }
        $bytes = (string) @\fread($this->workerParents[$generation]['stderr'], 65536);
        if ('' === $bytes && \feof($this->workerParents[$generation]['stderr'])) {
            \fclose($this->workerParents[$generation]['stderr']);
            unset($this->workerParents[$generation]['stderr'], $this->workerParents[$generation]['stderrBuf']);
            return;
        }
        $this->onlyHeartbeats = false;
        $this->workerParents[$generation]['stderrBuf'] .= $bytes;
        while (false !== ($eol = \strpos($this->workerParents[$generation]['stderrBuf'], "\n"))) {
            $line = \substr($this->workerParents[$generation]['stderrBuf'], 0, $eol);
            $this->workerParents[$generation]['stderrBuf'] = \substr($this->workerParents[$generation]['stderrBuf'], $eol + 1);
            $this->stderrLine($generation, $line);
        }
        \error_clear_last();
    }

    private function stderrLine(int $generation, string $line): void
    {
        // The ext reports an abnormal worker death: "phasync: workers: slot 2 (pid 1234) died: exit 7"
        if (\preg_match('/^phasync: workers: slot (\d+) \(pid (\d+)\) died: (.+)$/', $line, $m)) {
            $this->deathReport((int) $m[2], $m[3]);
            return;
        }
        if (\preg_match('/^phasync: workers: slot (\d+) keeps dying within (\d+)ms of starting \((\d+) times in a row\) -- delaying its next start by (\d+)ms$/', $line, $m)) {
            $this->logger->warning('Slot {slot} keeps dying within {fast}ms of starting ({n} times in a row); the worker-parent delays its next start by {ms} ms', [
                'slot' => (int) $m[1], 'fast' => $m[2], 'n' => (int) $m[3], 'ms' => $m[4],
            ]);
            return;
        }
        if ('' === \trim($line)) {
            return;
        }
        // Anything else is PHP's or the application's own stderr text (a PHP warning, a stray
        // echo, the engine's own fatal message, an ext notice): it belongs in the log as it is.
        $this->logger->error($line);
    }

    /** The worker-parent reported a worker's death: keep it for ended(), which logs it in context. */
    private function deathReport(int $pid, string $how): void
    {
        foreach ($this->connections as $w) {
            if ($w->pid === $pid) {
                $w->deathHow = $how;
                return;
            }
        }
        if (isset($this->recentlyEnded[$pid])) {
            // Its connection already ended: the exit closed the socket before the worker-parent's
            // reap reported the death -- the report came second, the usual order.
            [$slot, $up, $killReason, $fatal, $drained] = $this->recentlyEnded[$pid];
            unset($this->recentlyEnded[$pid]);
            if ($drained && 'exit 0' === $how) {
                $this->logger->info('Worker {pid} (slot {slot}) exited after draining (exit 0), up {up}', ['pid' => $pid, 'slot' => $slot, 'up' => $up]);
                $this->noteCleanDrain($pid, $slot);
            } else {
                $this->logDied($pid, $slot, $up, $how, $killReason, $fatal);
            }
            return;
        }
        $this->pendingDeaths[$pid] = $how; // its connection's EOF is still in flight
        if (\count($this->pendingDeaths) > 64) {
            \array_shift($this->pendingDeaths);
        }
    }

    /** "Worker 123 (slot 0) died: exit 7, up 1.2 s" / "died: signal 9 (SIGKILL), killed: watchdog: silent, up ..." */
    private function logDied(int $pid, int $slot, string $up, string $how, ?string $killReason, bool $fatal = false): void
    {
        $killed = null !== $killReason ? ', killed: ' . $killReason : '';
        $after  = $fatal ? ' (PHP fatal error)' : '';
        $this->logger->error("Worker {pid} (slot {slot}) died: {how}$after$killed, up {up}", [
            'pid' => $pid, 'slot' => $slot, 'how' => $how, 'up' => $up,
        ]);
        $this->lastHow[$slot] = $how; // ended() runs before the report arrives, usually
    }

    /** More bytes of a pending hello (12: slot, pid, generation, all 4-byte big endian). */
    private function readHello(int $id, float $now): void
    {
        $conn   = $this->pending[$id];
        $bytes  = (string) @\fread($conn, 64);
        if ('' === $bytes && \feof($conn)) {
            \fclose($conn);
            unset($this->pending[$id], $this->pendingIn[$id]);

            return;
        }
        $this->onlyHeartbeats = false;
        $this->pendingIn[$id] .= $bytes;
        if (\strlen($this->pendingIn[$id]) < 12) {
            return;
        }
        ['slot' => $slot, 'pid' => $pid, 'generation' => $generation] = \unpack('Nslot/Npid/Ngeneration', $this->pendingIn[$id]);
        unset($this->pending[$id], $this->pendingIn[$id]);
        if ($slot < 0 || $slot >= $this->numWorkers) {
            $this->logger->error('A lifecycle said hello for slot {slot}, outside 0..{max}: closing its connection', ['slot' => $slot, 'max' => $this->numWorkers - 1]);
            \fclose($conn);

            return;
        }
        $inbox = $this->inboxes->take();
        if (null === $inbox) {
            if (!$this->noInbox) {
                $this->noInbox = true;
                $this->logger->notice('Slot {slot}: waiting for a draining lifecycle to exit before starting another', ['slot' => $slot]);
            }
            \fclose($conn); // it will reconnect; a free inbox is checked again then

            return;
        }
        $this->noInbox = false;
        $delay         = ($this->failures[$slot] ?? 0) > 0 ? $this->backoff($this->failures[$slot]) : 0.0;
        \stream_set_blocking($conn, false);
        @\fwrite($conn, \pack('Nd', $inbox, $delay));
        $w                        = new LifecycleConnection($pid, $slot, $generation, $conn, $now, $now, $inbox);
        $this->connections[$id]   = $w;
        $replacing                = '';
        foreach ($this->connections as $other) {
            if ($other !== $w && $other->slot === $slot && $other->generation === $generation) {
                $replacing = ", replacing worker $other->pid"; // a recycle's predecessor still drains
                break;
            }
        }
        if ('' === $replacing && null !== ($old = $this->drainedForSlot($slot, $generation))) {
            $replacing = ", replacing worker $old"; // its predecessor drained out already
        }
        $this->logger->log($this->everReady ? 'notice' : 'info', "Started worker {pid} in slot {slot} (generation {generation})$replacing", ['pid' => $pid, 'slot' => $slot, 'generation' => $generation]);
    }

    /** Status bytes and frames of an established connection, as the worker's pipe used to carry them. */
    private function readStatus(LifecycleConnection $w, float $now): void
    {
        $bytes = (string) @\fread($w->pipe, 65536);
        if ('' === $bytes && \feof($w->pipe)) {
            $this->ended($w);

            return;
        }
        $w->lastSeen = $now;
        $w->in .= $bytes;
        $requested = false;
        $tail      = Topics::parse($w->in, function (string $topic, string $message) use (&$requested, $w) {
            $requested = true;
            if (Cache::TOPIC === $topic) {
                $reply = Cache::serve($this->cache, $message, function (?array $keys) {
                    $this->forgetAll(Topics::frame(Cache::FORGET, \serialize($keys)));
                });
                $this->send($w, Topics::frame(Cache::TOPIC, $reply));
            } elseif (Inboxes::TOPIC === $topic) {
                $reply = $this->inboxes->serve($w->inbox, $message, fn (string $topic) => $this->forgetAll(Topics::frame(Inboxes::FORGET, $topic)));
                if (null !== $reply) {
                    $this->send($w, Topics::frame(Inboxes::TOPIC, $reply));
                }
            } elseif ("\0drain" === $topic) {
                $w->drainWhy = $message;
            } else {
                throw new \UnexpectedValueException('A lifecycle sent a frame for an unknown topic');
            }
        });
        $this->onlyHeartbeats = $this->onlyHeartbeats && !$requested && '' === $w->in && '' === \trim($tail, '.');
        if (\str_contains($tail, 'R') && LifecycleConnection::STARTING === $w->state) {
            $this->onReady($w);
        }
        $w->recycle = $w->recycle || \str_contains($tail, 'C');
        $w->served  = $w->served || \str_contains($tail, 'Q');
        $w->fatal   = $w->fatal || \str_contains($tail, 'F');
        if (\str_contains($tail, 'W')) {
            // Its slot's lingering backlog is full: the recycle goes on once one of them
            // exits (the lifecycle retries its worker_detach() until then).
            $this->logger->notice('Slot {slot}: recycle waits for a lingering worker to exit', ['slot' => $w->slot]);
        }
        if (\str_contains($tail, 'D') && LifecycleConnection::DRAINING !== $w->state) {
            $w->state         = LifecycleConnection::DRAINING;
            $w->drainingSince = $now;
            // A lifecycle that asked to be recycled (its 'C' above) lingers with its upgraded
            // connections for up to --linger seconds, keeping its inbox for its subscriptions;
            // anything else that drains without being told (an operator's SIGTERM, systemd's)
            // is a plain drain, worth a warning unless the master is stopping too.
            $w->lingering = $w->recycle && $this->linger > 0;
            if (!$w->lingering) {
                $this->leaveInbox($w);
            }
            if (!$w->takeoverLogged && $w->generation === $this->generation) {
                $this->drainInfo[$w->pid] = [$w->pid, $w->slot, $w->recycle ? 'recycle' : 'reload', $w->lingering];
            }
            if (!$this->stopSignals && !$w->recycle) {
                $this->logger->warning('Worker {pid} (slot {slot}) is draining on a {why} the master did not send', ['pid' => $w->pid, 'slot' => $w->slot, 'why' => $w->drainWhy ?? 'signal']);
            }
            $this->takeoverLog($w);
        }
    }

    /**
     * Log "worker {new} took over, draining {old} ({why})", once per drained lifecycle: from
     * onReady() when the replacement readies after it drained, or from the 'D' handler above
     * when it drained first (its 'C' forks the replacement at once, so either order happens).
     * An older generation's lifecycles are drained by completeReload() instead, all together.
     */
    /**
     * A drain that completed (exit 0) before its replacement readied: takeoverLog() pairs the
     * two from here. A drain that did not -- the worker died of an exit() or a signal first --
     * is no takeover, and leaves nothing behind either.
     */
    /** The pid of the slot's worker that is draining (or drained out cleanly), if one is tracked. */
    private function drainedForSlot(int $slot, int $generation): ?int
    {
        if ($generation !== $this->generation) {
            return null;
        }
        foreach ($this->drainInfo as $info) {
            if ($info[1] === $slot) {
                return $info[0];
            }
        }

        return $this->pendingTakeovers[$slot][0] ?? null;
    }

    private function noteCleanDrain(int $pid, int $slot): void
    {
        $w = $this->drainInfo[$pid] ?? null;
        if (null === $w) {
            return;
        }
        unset($this->drainInfo[$pid]);
        [$oldPid, , $why, $lingering] = $w;
        foreach ($this->connections as $other) {
            if ($other->slot === $slot && $other->generation === $this->generation && LifecycleConnection::SERVING === $other->state && !$other->takeoverLogged) {
                // Its replacement readied first (the drain's exit 0 arrived last): the takeover
                // is complete right now
                $other->takeoverLogged = true;
                $this->logger->notice('Slot {slot}: worker {new} took over, draining {old} ({why})' . ($lingering ? '; its upgraded connections may stay up to {linger} s' : ''), [
                    'slot' => $slot, 'new' => $other->pid, 'old' => $oldPid, 'why' => $why, 'linger' => self::seconds($this->linger),
                ]);

                return;
            }
        }
        $this->pendingTakeovers[$slot] = $w;
    }

    private function takeoverLog(LifecycleConnection $w): void
    {
        if (LifecycleConnection::SERVING === $w->state && isset($this->pendingTakeovers[$w->slot])) {
            // Its predecessor drained and ended before this one readied: the pairing above
            // never had both connections at once, but the takeover is no less one
            [$oldPid, $why, $lingering] = $this->pendingTakeovers[$w->slot];
            unset($this->pendingTakeovers[$w->slot]);
            $this->logger->notice('Slot {slot}: worker {new} took over, draining {old} ({why})' . ($lingering ? '; its upgraded connections may stay up to {linger} s' : ''), [
                'slot' => $w->slot, 'new' => $w->pid, 'old' => $oldPid, 'why' => $why, 'linger' => self::seconds($this->linger),
            ]);
        }
        foreach ($this->connections as $other) {
            if ($other === $w || $other->slot !== $w->slot || $other->generation !== $w->generation || $other->takeoverLogged) {
                continue;
            }
            [$new, $old] = LifecycleConnection::SERVING === $w->state ? [$w, $other] : [$other, $w];
            if (LifecycleConnection::DRAINING !== $old->state || LifecycleConnection::SERVING !== $new->state) {
                continue;
            }
            $old->takeoverLogged = true;
            $this->logger->notice('Slot {slot}: worker {new} took over, draining {old} ({why})' . ($old->lingering ? '; its upgraded connections may stay up to {linger} s' : ''), [
                'slot' => $old->slot, 'new' => $new->pid, 'old' => $old->pid,
                'why' => $old->recycle ? 'recycle' : 'reload', 'linger' => self::seconds($this->linger),
            ]);
        }
    }

    private function onReady(LifecycleConnection $w): void
    {
        $now        = self::now();
        $w->state   = LifecycleConnection::SERVING;
        $w->readyAt = $now;
        $this->logger->info('Worker {pid} (slot {slot}) ready in {s} s', ['pid' => $w->pid, 'slot' => $w->slot, 's' => \round($now - $w->started, 2)]);
        $this->takeoverLog($w);
        if (!$this->everReady) {
            $this->everReady = true;
            $this->logger->notice($this->serving);
        }
        if ($w->generation === $this->generation) {
            // A lifecycle of it readied: the worker-parent itself isn't crash-looping.
            $this->workerParentFailures = 0;
        }
        if ($w->generation === $this->generation && $this->reloadInProgress()) {
            $readySlots = [];
            foreach ($this->connections as $other) {
                if ($other->generation === $this->generation && LifecycleConnection::SERVING === $other->state) {
                    $readySlots[$other->slot] = true;
                }
            }
            if (\count($readySlots) >= $this->numWorkers) {
                $this->completeReload();
            }
        }
    }

    /** Whether an older generation is still draining, or the current one's worker-parent is waiting to be retried: the reload isn't over yet. */
    private function reloadInProgress(): bool
    {
        if ($this->workerParentRetryAt > 0) {
            return true;
        }
        foreach ($this->workerParents as $generation => $info) {
            if ($generation !== $this->generation && !$info['stopping']) {
                return true;
            }
        }

        return false;
    }

    /** Every slot of the current generation is ready: drain every older one. */
    private function completeReload(): void
    {
        foreach ($this->workerParents as $generation => $info) {
            if ($generation === $this->generation || $info['stopping']) {
                continue;
            }
            $this->workerParents[$generation]['stopping'] = true;
            // SIGTERM to the worker-parent itself (not SIGKILL: its lifecycles still get to drain
            // on the byte protocol below) is what makes the ext stop re-running a slot once its
            // current lifecycle ends (see tests/worker_sigterm_graceful.phpt): without it, a
            // drained lifecycle would just reconnect and start another one, and the old
            // generation would never finish.
            \posix_kill($info['pid'], \SIGTERM);
            $drained = 0;
            foreach ($this->connections as $w) {
                if ($w->generation !== $generation) {
                    continue;
                }
                if ($w->lingering) {
                    // A reload ends the lingering too: its connections close now, within the
                    // grace period (the onShutdown callbacks tell them, a WebSocket its 1001).
                    $this->send($w, 'T');
                    $w->lingering     = false;
                    $w->drainingSince = self::now();
                    ++$drained;
                } elseif (LifecycleConnection::DRAINING !== $w->state) {
                    $this->drain($w);
                    ++$drained;
                }
            }
            $this->logger->notice('Reload complete: draining {n} lifecycles of the old generation {generation}', ['n' => $drained, 'generation' => $generation]);
        }
    }

    private function reap(): void
    {
        while (($pid = \pcntl_waitpid(-1, $status, \WNOHANG)) > 0) {
            foreach ($this->workerParents as $generation => $info) {
                if ($info['pid'] === $pid) {
                    if (isset($info['stderr'])) {
                        \fclose($info['stderr']);
                    }
                    unset($this->workerParents[$generation]);
                    $this->workerParentExited($generation, $status);
                    continue 2;
                }
            }
            $this->logger->info('Reaped process {pid}, not the worker-parent: {how}', ['pid' => $pid, 'how' => $this->describe($status, false)]);
        }
    }

    /** The worker-parent of $generation ended: every lifecycle of that generation is gone too. */
    private function workerParentExited(int $generation, int $status): void
    {
        unset($this->drainedOnce[$generation]);
        $expected = $this->stopSignals > 0 || (isset($this->workerParents[$generation]) && $this->workerParents[$generation]['stopping']);
        foreach ($this->connections as $id => $w) {
            if ($w->generation === $generation) {
                $this->ended($w, assumeGone: true);
                unset($this->connections[$id]);
            }
        }
        if ($this->stopSignals || null !== $this->exitCode) {
            return; // stopping: signals, or a give-up (every worker failed to start)
        }
        if ($generation !== $this->generation) {
            if (!$expected) {
                $this->logger->warning('An old generation\'s worker-parent died while draining: {how}', ['how' => $this->describe($status, false)]);
            }

            return;
        }
        $olderStillServing = false;
        foreach ($this->workerParents as $otherGeneration => $info) {
            if ($otherGeneration !== $generation && !$info['stopping']) {
                $olderStillServing = true;
                break;
            }
        }
        if ($olderStillServing) {
            // A reload's new worker-parent died before any of its slots was ready: the old
            // generation keeps serving untouched; retry starting this one, with backoff.
            $delay = $this->backoff(++$this->workerParentFailures);
            $this->logger->error('The new worker-parent (generation {generation}) died before it was ready: {how}; retrying in {delay} s, the old code keeps serving', [
                'generation' => $generation, 'how' => $this->describe($status, false), 'delay' => self::seconds($delay),
            ]);
            $this->workerParentRetryAt = self::now() + $delay;

            return;
        }
        $this->workerParentFailures = 0;
        $this->logger->error('The worker-parent process died: {how}; restarting it', ['how' => $this->describe($status, false)]);
        $this->startWorkerParent();
    }

    /** A lifecycle's connection ended: free its inbox, and act as a failed start if it never served. */
    private function ended(LifecycleConnection $w, bool $assumeGone = false): void
    {
        if ($w->pipe) {
            \fclose($w->pipe);
            $w->pipe = null;
        }
        $this->leaveInbox($w);
        $this->inboxes->release($w->inbox);
        foreach ($this->connections as $id => $other) {
            if ($other === $w) {
                unset($this->connections[$id]);
                break;
            }
        }
        $now    = self::now();
        $ctx    = ['pid' => $w->pid, 'slot' => $w->slot, 'up' => self::duration($now - $w->started)];
        $how    = $w->deathHow ?? ($this->pendingDeaths[$w->pid] ?? null);
        unset($this->pendingDeaths[$w->pid]);

        if (LifecycleConnection::DRAINING === $w->state) {
            $this->drainedOnce[$w->generation][$w->slot] = true;
            $this->killIfFullyDrained($w->generation);
            if (null === $how) {
                // Draining, but how it ended is not known yet (the worker-parent's reap
                // report usually arrives after this EOF): a SIGTERM mid-shutdown, a fatal
                // in a shutdown function, is no clean drain -- say so when it arrives.
                $this->recentlyEnded[$w->pid] = [$w->slot, $ctx['up'], $w->killReason, $w->fatal, true];
                if (\count($this->recentlyEnded) > 64) {
                    \array_shift($this->recentlyEnded);
                }

                return;
            }
            if ('exit 0' === $how) {
                $this->logger->info('Worker {pid} (slot {slot}) exited after draining (exit 0), up {up}', $ctx);
                $this->noteCleanDrain($w->pid, $w->slot);

                return;
            }
            $this->logDied($w->pid, $w->slot, $ctx['up'], $how, $w->killReason, $w->fatal);

            return;
        }
        if (null !== $how) {
            // The worker-parent reported the death on its stderr (it usually arrives after
            // this EOF: the exit closes the socket at once, the reap a lot tick later).
            $this->logDied($w->pid, $w->slot, $ctx['up'], $how, $w->killReason, $w->fatal);
        } else {
            $this->recentlyEnded[$w->pid] = [$w->slot, $ctx['up'], $w->killReason, $w->fatal, false];
            if (\count($this->recentlyEnded) > 64) {
                \array_shift($this->recentlyEnded);
            }
        }
        if ($w->served || null !== $w->readyAt) {
            // It served, or was ready: the ext simply runs its next lifecycle (or, if the process
            // itself died, replaces it); not a failed start, and resets the slot's backoff
            $this->failures[$w->slot] = 0;

            return;
        }
        if ($w->generation !== $this->generation) {
            return; // an old generation's lifecycle, during a reload: not this slot's backoff
        }
        $delay = $this->backoff(++$this->failures[$w->slot]);
        $ctx['delay'] = self::seconds($delay);
        if (null === $this->exitCode && !$this->everReady && \min($this->failures) > 0) {
            $this->logger->critical('The application failed to start: every worker failed; stopping');
            $this->exitCode = 1;

            return;
        }
        if ($this->failures[$w->slot] >= self::CRASH_LOOP) {
            $this->logger->critical('Slot {slot} is crash-looping: {n} failed starts in a row (last: {how}); next start in {delay} s', $ctx + ['n' => $this->failures[$w->slot], 'how' => $how ?? ($this->lastHow[$w->slot] ?? 'unknown')]);
        } else {
            $this->logger->warning('Restarting slot {slot} in {delay} s', $ctx);
        }
    }

    /**
     * Once every slot of a stopping generation has drained at least once, kill its worker-parent's
     * process group outright, rather than waiting for the process to exit on its own: see
     * $drainedOnce.
     */
    private function killIfFullyDrained(int $generation): void
    {
        $info = $this->workerParents[$generation] ?? null;
        if (null === $info || !$info['stopping']) {
            return;
        }
        if (\count($this->drainedOnce[$generation] ?? []) < $this->numWorkers) {
            return;
        }
        unset($this->drainedOnce[$generation]);
        \posix_kill($info['pid'], \SIGKILL); // its lifecycles die with it (PR_SET_PDEATHSIG)
    }

    /**
     * Kill lifecycles past a deadline: not ready in time, or silent (stuck) for longer than the
     * watchdog allows. Also clears the failures of slots whose lifecycle has now been ready long
     * enough.
     */
    private function deadlines(): void
    {
        $now = self::now();
        if ($now - $this->lastDeadlines > self::PAUSED) {
            foreach ($this->connections as $w) {
                $w->lastSeen = $now;
            }
        }
        $this->lastDeadlines = $now;
        $stuck = [];
        foreach ($this->connections as $w) {
            if (LifecycleConnection::STARTING === $w->state && $now - $w->started > self::READY_TIMEOUT) {
                $this->kill($w, 'not ready after ' . self::seconds(self::READY_TIMEOUT) . ' s', 'error');
            } elseif (LifecycleConnection::STARTING !== $w->state && $this->watchdog > 0 && $now - $w->lastSeen > $this->watchdog) {
                $silent  = \round($now - $w->lastSeen, 1);
                $stuck[] = [$w, $silent];
                \posix_kill($w->pid, \SIGQUIT);
                $this->logger->error('Worker {pid} (slot {slot}) sent no heartbeat for {s} s: killing it (busy loop or blocking call)', ['pid' => $w->pid, 'slot' => $w->slot, 's' => $silent]);
            } elseif (LifecycleConnection::DRAINING === $w->state && $now - $w->drainingSince > $this->grace + ($w->lingering ? $this->linger : 0)) {
                $this->kill($w, $w->lingering ? 'linger time and grace expired' : 'grace expired', 'warning');
            }
            if (LifecycleConnection::SERVING === $w->state && ($this->failures[$w->slot] ?? 0) > 0 && $now - $w->readyAt >= self::STABLE_AFTER
                && $w->generation === $this->generation) {
                $this->failures[$w->slot] = 0;
                $this->logger->notice('Slot {slot} recovered', ['slot' => $w->slot]);
            }
        }
        if ($stuck) {
            \usleep((int) (self::REPORT_WAIT * 1_000_000));
            foreach ($stuck as [$w]) {
                $this->kill($w, 'watchdog: silent', null);
            }
        }
    }

    private function forgetAll(string $frame): void
    {
        foreach ($this->connections as $w) {
            if (LifecycleConnection::STARTING !== $w->state) {
                $this->send($w, $frame);
            }
        }
    }

    private function leaveInbox(LifecycleConnection $w): void
    {
        $this->inboxes->leave($w->inbox, fn (string $topic) => $this->forgetAll(Topics::frame(Inboxes::FORGET, $topic)));
    }

    private function send(LifecycleConnection $w, string $bytes): void
    {
        if (!$w->pipe) {
            return;
        }
        $w->out .= $bytes;
        $this->flush($w);
    }

    private function flush(LifecycleConnection $w): void
    {
        if (!$w->pipe) {
            return;
        }
        $w->out = \substr($w->out, (int) @\fwrite($w->pipe, $w->out));
    }

    private function kill(LifecycleConnection $w, string $reason, ?string $level): bool
    {
        if (null !== $w->killReason) {
            return false;
        }
        $w->killReason = $reason;
        \posix_kill($w->pid, \SIGKILL);
        if (null !== $level) {
            $this->logger->log($level, 'Killing worker {pid} (slot {slot}): {reason}', ['pid' => $w->pid, 'slot' => $w->slot, 'reason' => $reason]);
        }

        return true;
    }

    /**
     * Start a worker-parent of the next generation on the current code, inheriting the same
     * listener and inbox descriptors; its lifecycles' hellos complete the reload, see onReady().
     */
    private function reload(string $why): void
    {
        $this->reopenLog();
        if ($this->reloadInProgress()) {
            $this->logger->notice('Reload requested ({why}), but one is already in progress: ignored', ['why' => $why]);

            return;
        }
        ++$this->generation;
        for ($slot = 0; $slot < $this->numWorkers; ++$slot) {
            $this->failures[$slot] = 0;
        }
        foreach ($this->connections as $w) {
            // A reload ends the lingering too, at once: its code is outdated whatever its
            // recycle said, its connections close within the grace period (the onShutdown
            // callbacks tell them, a WebSocket its 1001) -- and its inbox frees up for the
            // next generation's lifecycle in the slot, which must not wait for it.
            if ($w->lingering) {
                $this->send($w, 'T');
                $w->lingering     = false;
                $w->drainingSince = self::now();
                $this->leaveInbox($w);
            }
        }
        $this->logger->notice('Reload requested ({why}): starting generation {generation}', ['why' => $why, 'generation' => $this->generation]);
        $this->startWorkerParent();
    }

    private function reopenLog(): void
    {
        if ($this->logger instanceof Logger) {
            $this->logger->reopen();
        }
        foreach ($this->connections as $w) {
            $this->send($w, 'L');
        }
    }

    private function drain(LifecycleConnection $w, bool $linger = false, string $byte = 'T'): void
    {
        $this->send($w, $linger ? 'G' : $byte);
        $w->state         = LifecycleConnection::DRAINING;
        $w->drainingSince = self::now();
        $w->lingering     = $linger;
        if (!$linger) {
            $this->leaveInbox($w);
        }
    }

    private function stopAll(int $code): int
    {
        $start = self::now();
        $this->logger->notice('Shutting down: draining {n} lifecycles (grace {g} s)', ['n' => \count($this->connections), 'g' => self::seconds($this->grace)]);
        $this->drainAll('shutdown');
        $this->logger->notice('Stopped in {s} s', ['s' => \round(self::now() - $start, 2)]);
        System::unlinkSocket('unix:' . $this->masterSocketPath);

        return $code;
    }

    private function drainAll(string $why): void
    {
        $start = self::now();
        foreach ($this->workerParents as $generation => $info) {
            $this->workerParents[$generation]['stopping'] = true;
            // SIGTERM the worker-parent first, same as completeReload(): it's what stops the ext
            // from running another lifecycle once the one we're about to drain below finishes.
            // Draining first (the previous order here) left a window where a lifecycle that had
            // just drained reconnected and the ext started a fresh one, which then raced the
            // shutdown -- the worker-parent's replacement process then failed to reach the master
            // (socket refused mid-teardown) and crash-looped (swerve#72).
            \posix_kill($info['pid'], \SIGTERM);
        }
        foreach ($this->connections as $w) {
            if (LifecycleConnection::DRAINING !== $w->state) {
                $this->drain($w, false, 'shutdown' === $why ? 'X' : 'T');
            }
        }
        $deadline = $start + $this->grace;
        $signals  = \min($this->stopSignals, 1);
        while (($this->connections || $this->workerParents) && self::now() < $deadline) {
            $this->acceptPending();
            $this->readPipes(0.1);
            $this->reap();
            if ($this->stopSignals > $signals) {
                $this->logger->warning('Second signal: killing now');
                break;
            }
        }
        if ($this->connections || $this->workerParents) {
            $this->logger->warning('Grace expired: killing the worker-parent(s)');
            foreach ($this->workerParents as $info) {
                \posix_kill($info['pid'], \SIGKILL); // its lifecycles die with it (PR_SET_PDEATHSIG)
            }
            $until = self::now() + self::KILL_WAIT;
            while ($this->workerParents && self::now() < $until) {
                $this->reap();
                \usleep(10_000);
            }
            if ($this->workerParents) {
                $this->logger->emergency('The worker-parent process did not die after SIGKILL');
            }
        }
    }

    private function changedFile(): ?string
    {
        $now = $this->snapshot();
        if ($now === $this->scanSeen) {
            $this->scanPending = null;

            return null;
        }
        if ($now !== $this->scanPending) {
            $this->scanPending = $now;

            return null;
        }
        $seen               = $this->scanSeen;
        $this->scanSeen     = $now;
        $this->scanPending  = null;
        foreach ($now as $file => $stat) {
            if (($seen[$file] ?? null) !== $stat) {
                return $file;
            }
        }

        return (string) \array_key_first(\array_diff_key($seen, $now));
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        \clearstatcache();
        $files = [$this->monitorDir => (string) \realpath($this->monitorDir)];
        try {
            $dirs = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->monitorDir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => !\str_starts_with($f->getFilename(), '.') && (!$f->isDir() || 'vendor' !== $f->getFilename()),
            );
            foreach (new \RecursiveIteratorIterator($dirs, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
                if ('php' === $file->getExtension() && false !== ($stat = @\stat($file->getPathname()))) {
                    $files[$file->getPathname()] = $stat['mtime'] . ':' . $stat['size'];
                }
            }
        } catch (\UnexpectedValueException) {
        }
        $installed = "{$this->monitorDir}/vendor/composer/installed.php";
        if (false !== ($stat = @\stat($installed))) {
            $files[$installed] = $stat['mtime'] . ':' . $stat['size'];
        }

        return $files;
    }

    private function backoff(int $failures): float
    {
        return \min(self::BACKOFF_FIRST * 2 ** ($failures - 1), self::BACKOFF_MAX);
    }

    private function describe(int $status, bool $fatal): string
    {
        if (\pcntl_wifexited($status)) {
            $code = \pcntl_wexitstatus($status);

            return "exit $code" . (255 === $code && $fatal ? ' (PHP fatal error, e.g. memory_limit; see log)' : '');
        }
        $signal = \pcntl_wtermsig($status);

        return "signal $signal (" . System::signalName($signal) . ')';
    }

    private static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    private static function seconds(float $seconds): string
    {
        return \rtrim(\rtrim(\sprintf('%.1f', $seconds), '0'), '.');
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 60 ? \sprintf('%.1f s', $seconds) : \sprintf('%dm%02ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
