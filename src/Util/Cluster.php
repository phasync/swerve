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
 * current code (a fresh `php` process: a fresh opcache compile), inheriting the same listener and
 * inbox descriptors, so both can accept() meanwhile ("the lot keeps accepting"). Once every slot
 * of the new generation is ready, every lifecycle of the old generation is told to drain ('T');
 * the old worker-parent is reaped once they are gone, or SIGKILLed (by process group: it runs
 * under `setsid`) after the grace period. Signals: SIGTERM, SIGINT or SIGQUIT drain every
 * lifecycle within the grace period, SIGKILL what remains, and stop the worker-parent the same
 * way. SIGUSR1 only reopens the log file.
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
    /** Descriptor number the HTTP listener is passed at (phasync.workers_listener matches it). */
    public const LISTENER_FD = 3;
    /** First descriptor number of the inboxes: their read ends, then their write ends, see Inboxes::adoptFromFds(). */
    public const INBOX_FD_BASE = 4;

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
     *                                                 `-d phasync.workers_listener=` . self::LISTENER_FD is in it already when $listener is given
     * @param array<string, string> $workerParentEnv  env common to every generation (SWERVE_MASTER* and the fd layout are added here)
     * @param resource|null         $listener         the HTTP listener, at descriptor self::LISTENER_FD in every generation; null for --no-http
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
        private $listener = null,
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
     * proc_open the worker-parent of the current (or, for a reload, the next) generation, under
     * `setsid` so the master can signal it and every lifecycle it forks as one process group
     * without signalling itself.
     */
    private function startWorkerParent(): void
    {
        $generation = $this->generation;
        [$reads, $writes] = $this->inboxes->streams();
        $env        = $this->workerParentEnv + [
            'SWERVE_MASTER'        => $this->masterSocketPath,
            'SWERVE_MASTER_PID'    => (string) $this->masterPid,
            'SWERVE_GENERATION'    => (string) $generation,
            'SWERVE_INBOX_COUNT'   => (string) \count($reads),
            'SWERVE_INBOX_FD_BASE' => (string) self::INBOX_FD_BASE,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if (null !== $this->listener) {
            $descriptors[self::LISTENER_FD] = $this->listener;
        }
        $fd = self::INBOX_FD_BASE;
        foreach ($reads as $stream) {
            $descriptors[$fd++] = $stream;
        }
        foreach ($writes as $stream) {
            $descriptors[$fd++] = $stream;
        }
        $process = \proc_open(['setsid', ...$this->workerParentArgv], $descriptors, $pipes, null, $env);
        if (false === $process) {
            throw new \RuntimeException('Could not start the worker-parent process');
        }
        foreach ($pipes as $pipe) {
            \fclose($pipe); // its stdio is not ours to read: it logs nothing of its own
        }
        $pid = \proc_get_status($process)['pid'];
        $this->workerParents[$generation] = ['pid' => $pid, 'process' => $process, 'started' => self::now(), 'stopping' => false];
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
            $this->readStatus($this->connections[$id], $now);
        }
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
        $this->logger->log($this->everReady ? 'notice' : 'info', 'Started lifecycle {pid} in slot {slot} (generation {generation})', ['pid' => $pid, 'slot' => $slot, 'generation' => $generation]);
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
        if (\str_contains($tail, 'D') && LifecycleConnection::DRAINING !== $w->state) {
            $w->state         = LifecycleConnection::DRAINING;
            $w->drainingSince = $now;
            $this->leaveInbox($w);
            if (!$this->stopSignals) {
                $this->logger->warning('Lifecycle {pid} (slot {slot}) is draining on a signal the master did not send', ['pid' => $w->pid, 'slot' => $w->slot]);
            }
        }
    }

    private function onReady(LifecycleConnection $w): void
    {
        $now        = self::now();
        $w->state   = LifecycleConnection::SERVING;
        $w->readyAt = $now;
        $this->logger->info('Lifecycle {pid} (slot {slot}) ready in {s} s', ['pid' => $w->pid, 'slot' => $w->slot, 's' => \round($now - $w->started, 2)]);
        if (!$this->everReady) {
            $this->everReady = true;
            $this->logger->notice($this->serving);
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

    /** Whether an older generation is still draining: the reload isn't over yet. */
    private function reloadInProgress(): bool
    {
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
            $drained = 0;
            foreach ($this->connections as $w) {
                if ($w->generation === $generation && LifecycleConnection::DRAINING !== $w->state) {
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
        $expected = $this->stopSignals > 0 || (isset($this->workerParents[$generation]) && $this->workerParents[$generation]['stopping']);
        foreach ($this->connections as $id => $w) {
            if ($w->generation === $generation) {
                $this->ended($w, assumeGone: true);
                unset($this->connections[$id]);
            }
        }
        if ($this->stopSignals) {
            return;
        }
        if ($generation === $this->generation) {
            $this->logger->error('The worker-parent process died: {how}; restarting it', ['how' => $this->describe($status, false)]);
            $this->startWorkerParent();
        } elseif (!$expected) {
            $this->logger->warning('An old generation\'s worker-parent died while draining: {how}', ['how' => $this->describe($status, false)]);
        }
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
        if (LifecycleConnection::DRAINING === $w->state) {
            $this->logger->info('Lifecycle {pid} (slot {slot}) ended after draining (up {up})', $ctx);

            return;
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
        if (!$this->everReady && \min($this->failures) > 0) {
            $this->logger->critical('The application failed to start: every worker failed; stopping');
            $this->exitCode = 1;

            return;
        }
        if ($this->failures[$w->slot] >= self::CRASH_LOOP) {
            $this->logger->critical('Slot {slot} is crash-looping: {n} failed starts in a row; next start in {delay} s', $ctx + ['n' => $this->failures[$w->slot]]);
        } else {
            $this->logger->warning('Lifecycle {pid} (slot {slot}) failed to start; retrying in {delay} s', $ctx);
        }
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
                $this->logger->error('Lifecycle {pid} (slot {slot}) sent no heartbeat for {s} s: killing it (busy loop or blocking call)', ['pid' => $w->pid, 'slot' => $w->slot, 's' => $silent]);
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
            $this->logger->log($level, 'Killing lifecycle {pid} (slot {slot}): {reason}', ['pid' => $w->pid, 'slot' => $w->slot, 'reason' => $reason]);
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
        foreach ($this->connections as $w) {
            if (LifecycleConnection::DRAINING !== $w->state) {
                $this->drain($w, false, 'shutdown' === $why ? 'X' : 'T');
            }
        }
        foreach ($this->workerParents as $generation => $info) {
            $this->workerParents[$generation]['stopping'] = true;
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
            $this->logger->warning('Grace expired: killing the worker-parent process group(s)');
            foreach ($this->workerParents as $info) {
                \posix_kill(-$info['pid'], \SIGKILL); // setsid: its pgid is its own pid, covering every lifecycle it forked
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
