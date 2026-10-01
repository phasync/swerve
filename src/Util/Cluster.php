<?php

namespace Swerve\Util;

use phasync\Util\LruCache;
use Psr\Log\LoggerInterface;
use Swerve\Cache;

/**
 * The master process: forks one worker per slot and supervises them until stopped.
 *
 * The master is a plain stream_select() loop, without phasync, and never loads the
 * application: forks happen outside any event loop, so every worker starts a clean one and
 * loads the current application code, and an application bug can't take the master down.
 *
 * Each worker has a socket pair to the master. The worker writes '.' as a heartbeat four
 * times a second, from its event loop, 'R' once it listens, 'Q' when its first request starts,
 * 'C' to ask to be recycled, and 'D' when it drains; the end of the pair tells the master the
 * worker is gone. The master writes 'T' to make a worker drain and 'L' to make it reopen the
 * log file: not signals, which would cut short the blocking calls of the requests in flight.
 * Among these bytes go framed requests (see Topics::frame()): the cache's, and the subscriptions'
 * (see Inboxes), which the master answers; published messages don't pass the master. A worker
 * that stays silent longer than the watchdog timeout is stuck (a busy loop or a blocking call
 * stalls its whole event loop): it is sent SIGQUIT, to log what it is stuck in, and SIGKILLed a
 * moment later.
 *
 * Signals: SIGTERM, SIGINT or SIGQUIT drain every worker within the grace period, SIGKILL what
 * remains and exit; a second one kills at once. SIGHUP or SIGUSR2 reopen the log file (for log
 * rotation) and replace the workers one at a time with ones running the current code (a
 * rolling reload): the new worker listens (SO_REUSEPORT) before the old one drains, so there is
 * always a listener. SIGUSR1 only reopens the log file. SIGQUIT, SIGUSR1 and SIGUSR2 mean what
 * they mean to php-fpm, whose deploy and logrotate scripts send them. A worker that asks to be
 * recycled is replaced the same way as in a reload. One replacement is in flight at a time.
 *
 * A worker that dies is restarted in its slot. One that dies before it was ready, or soon
 * after without having served a request, is a failed start, and its slot backs off
 * exponentially; one that served started fine, and is restarted at once, as backing off would
 * turn a request that kills its worker into an outage. A replacement (reload or recycle) that
 * fails to start leaves the old worker serving, and is retried in the same slot after its
 * backoff: a passing failure, such as a database away for a moment, must not leave the old code
 * serving for good, nor the next crash mix the new code in. When every slot failed to start before
 * any worker became ready, the application can't start at all: the master stops and exits
 * with the last worker's exit code, or 1 when that is 0 (an application that exit()s or die()s
 * while loading): a failed start never looks like a clean stop to a supervisor.
 *
 * The master reaps every child that ends, also those it did not fork: as PID 1 in a container,
 * or a subreaper, it inherits the orphans of the application's background jobs, and after
 * `job & exec swerve` the job is its child.
 *
 * Times are from the monotonic clock, which a change of the system time doesn't move.
 *
 * A slot can briefly hold several processes (one starting, one serving, several draining);
 * slot-keyed resources must tolerate the overlap.
 */
final class Cluster
{
    /** Longest stream_select() wait: bounds how late any deadline is handled. */
    public const TICK = 0.25;
    /**
     * Shortest round of the master's loop after one that only read heartbeats. Any byte from a
     * worker ends the wait, and a round goes over every worker, so the heartbeats of many
     * workers, out of step, would each cost a round: at 256 workers, half a core while idle.
     * The bytes wait in the pipes meanwhile; a signal still ends the wait. After a round with
     * news (ready, recycle, a worker gone), the next one waits for nothing more, so that a
     * handover goes on at once.
     */
    public const ROUND_MIN = 0.02;

    /** What Swerve::cache() holds, for every worker: see Cache. */
    private LruCache $cache;
    private Claims $claims;
    /** The workers' inboxes, and who subscribes to what. */
    private Inboxes $inboxes;
    /** No inbox was free to start a worker: logged once, until one starts. */
    private bool $noInbox = false;
    /** Application load and listen: a worker not ready by then is killed as a failed start. */
    public const READY_TIMEOUT = 60.0;
    /** Ready this long, or having served: not part of a crash loop, and resets the slot's failures. */
    public const STABLE_AFTER = 5.0;
    public const BACKOFF_FIRST = 0.5;
    public const BACKOFF_MAX = 30.0;
    /** Consecutive failed starts from which the log is critical. */
    public const CRASH_LOOP = 3;
    /** How long a stuck worker gets to log what it is stuck in, after SIGQUIT and before SIGKILL. */
    public const REPORT_WAIT = 0.1;
    /** After SIGKILL, the longest wait for the processes to be reaped before the master exits anyway. */
    public const KILL_WAIT = 5.0;
    public const MONITOR_EVERY = 1.0;
    /**
     * --monitor's scan of a large tree takes the master's time from its workers: rescans wait
     * at least this many times as long as the last scan took.
     */
    public const MONITOR_SHARE = 10;
    /**
     * A gap this long between two rounds of the master's loop means the master itself did not
     * run (the process group stopped and continued, a suspended VM): see deadlines().
     */
    public const PAUSED = 1.0;

    /** @var array<int, WorkerProcess> by pid */
    private array $workers = [];
    /** @var array<int, int> consecutive failed starts per slot */
    private array $failures = [];
    /** @var array<int, float> per slot: no start before this time (backoff) */
    private array $nextStart = [];
    private int $generation = 0;
    private bool $everReady = false;
    /** Set when the application failed to start: the master stops with this exit code. */
    private ?int $exitCode = null;
    private int $stopSignals = 0;
    /** 'SIGHUP' or 'SIGUSR2' */
    private ?string $reloadSignal = null;
    private bool $reopenSignal = false;
    private ?float $reloadStarted = null;
    private float $nextScan = 0;
    /** When deadlines() last ran, to tell when the master itself was paused. */
    private float $lastDeadlines = 0;
    /** When readPipes() last started waiting, and whether it read only heartbeats: see ROUND_MIN. */
    private float $lastRead = 0;
    private bool $onlyHeartbeats = false;
    /** @var array<string, string>|null files and their mtime:size the workers run */
    private ?array $scanSeen = null;
    /** @var array<string, string>|null a changed snapshot, reloaded once it stays the same for a scan */
    private ?array $scanPending = null;
    private readonly int $masterPid;

    /**
     * @param float       $grace      seconds a draining worker gets before SIGKILL
     * @param float       $watchdog   seconds of silence after which a worker is killed; 0 = off
     * @param string|null $monitorDir reload when a PHP file below it changes
     * @param string      $serving    what is served where, logged once the first worker is ready
     */
    public function __construct(
        private readonly int $numWorkers,
        private readonly LoggerInterface $logger,
        private readonly float $grace,
        private readonly float $watchdog,
        private readonly ?string $monitorDir,
        private readonly string $serving,
        int $cacheBytes = 64 << 20,
    ) {
        $this->cache  = new LruCache(maxBytes: $cacheBytes);
        $this->claims = new Claims();
        // Twice the slots: a replacement starts while the worker it replaces drains
        $this->inboxes = new Inboxes(2 * $numWorkers);
        $this->masterPid = \posix_getpid();
        $this->logger->info('Master process {pid}, {n} workers', ['pid' => $this->masterPid, 'n' => $numWorkers]);
        if (!\extension_loaded('phasync')) {
            $this->logger->notice('phasync-ext is not loaded: fine for development, but in production it lifts the limit of about 960 connections per worker and speeds up waiting (composer require phasync/phasync-ext)');
        }
        if ('0' === \trim((string) @\file_get_contents('/proc/sys/net/ipv4/tcp_migrate_req'))) {
            $this->logger->info('net.ipv4.tcp_migrate_req=0: connections queued on a closing listener are reset during reload/recycle; set it to 1 for lossless handovers');
        }
    }

    /**
     * In the master: supervise until stopped, and return the exit code. In a new worker
     * process: return its Worker, right after the fork.
     */
    public function run(): Worker|int
    {
        \pcntl_signal(\SIGTERM, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGINT, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGQUIT, function () { ++$this->stopSignals; });
        \pcntl_signal(\SIGHUP, function () { $this->reloadSignal = 'SIGHUP'; });
        \pcntl_signal(\SIGUSR2, function () { $this->reloadSignal = 'SIGUSR2'; });
        \pcntl_signal(\SIGUSR1, function () { $this->reopenSignal = true; });
        for ($slot = 0; $slot < $this->numWorkers; ++$slot) {
            $this->failures[$slot]  = 0;
            $this->nextStart[$slot] = 0.0;
        }
        if (null !== $this->monitorDir) {
            $this->scanSeen = $this->snapshot();
            $this->logger->info('Watching {dir} for changes', ['dir' => $this->monitorDir]);
        }

        while (true) {
            if ($worker = $this->fill()) {
                return $worker;
            }
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
            if ($worker = $this->handover()) {
                return $worker;
            }
            // Last in the round: after it, the heartbeats that came meanwhile are read before the
            // next deadlines()
            if (null !== $this->monitorDir && self::now() >= $this->nextScan) {
                $start = self::now();
                $file  = $this->changedFile();
                $now   = self::now();
                $this->nextScan      = $now + \max(self::MONITOR_EVERY, self::MONITOR_SHARE * ($now - $start));
                $this->lastDeadlines = $now; // the master was busy, not paused
                if (null !== $file) {
                    $this->logger->notice('Change detected in {file}; reloading', ['file' => $file]);
                    $this->reload("changed: $file");
                }
            }
        }
    }

    /**
     * Start a worker in every slot that has none starting or serving, once its backoff is over.
     */
    private function fill(): ?Worker
    {
        $taken = [];
        foreach ($this->workers as $w) {
            if (WorkerProcess::DRAINING !== $w->state) {
                $taken[$w->slot] = true;
            }
        }
        $now = self::now();
        for ($slot = 0; $slot < $this->numWorkers; ++$slot) {
            if (!isset($taken[$slot]) && $now >= $this->nextStart[$slot] && ($worker = $this->spawn($slot, null))) {
                return $worker;
            }
        }

        return null;
    }

    /**
     * Fork a worker for the slot. Returns its Worker in the child, null in the master.
     *
     * @param int|null $replaces the SERVING worker it takes over from once ready
     */
    private function spawn(int $slot, ?int $replaces): ?Worker
    {
        $inbox = $this->inboxes->take();
        if (null === $inbox) {
            // Draining workers hold them until they exit, within the grace period
            if (!$this->noInbox) {
                $this->noInbox = true;
                $this->logger->notice('Slot {slot}: waiting for a draining worker to exit before starting another', ['slot' => $slot]);
            }

            return null;
        }
        $this->noInbox = false;
        [$master, $child] = System::socketPair();
        // Held until the worker installed its own handlers: the master's must not run in it
        \pcntl_sigprocmask(\SIG_BLOCK, [\SIGTERM, \SIGINT, \SIGHUP, \SIGUSR1, \SIGUSR2, \SIGQUIT]);
        $pid = \pcntl_fork();
        if (0 === $pid) {
            \fclose($master);
            foreach ($this->workers as $w) {
                if ($w->pipe) {
                    \fclose($w->pipe);
                }
            }
            $this->workers = [];
            $logger        = $this->logger instanceof Logger ? $this->logger->withSource((string) $slot) : $this->logger;
            if (null !== $cpus = System::pinToNumaNode($slot)) {
                $logger->info('Pinned to the NUMA node of CPUs {cpus}', ['cpus' => $cpus]);
            }

            [$read, $peers] = $this->inboxes->adopt($inbox);

            return new Worker($slot, $child, $this->masterPid, $this->grace, $this->watchdog, $logger, $inbox, $read, $peers);
        }
        \pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM, \SIGINT, \SIGHUP, \SIGUSR1, \SIGUSR2, \SIGQUIT]);
        $now = self::now();
        if (-1 === $pid) {
            \fclose($master);
            \fclose($child);
            $this->inboxes->release($inbox);
            $this->logger->error('Fork failed for slot {slot}: {error}', ['slot' => $slot, 'error' => \pcntl_strerror(\pcntl_get_last_error())]);
            $this->nextStart[$slot] = $now + $this->backoff(++$this->failures[$slot]);

            return null;
        }
        \fclose($child);
        \stream_set_blocking($master, false);
        $this->workers[$pid] = new WorkerProcess($pid, $slot, $this->generation, $master, $now, $now, $inbox, $replaces);
        // Once serving, a start is a restart or a replacement, and its pid is news
        // A restart is news; a replacement is logged as it takes over, see onReady()
        $this->logger->log($this->everReady && null === $replaces ? 'notice' : 'info', 'Started worker {pid} in slot {slot} (generation {generation})' . (null !== $replaces ? ', replacing {old}' : ''), [
            'pid' => $pid, 'slot' => $slot, 'generation' => $this->generation, 'old' => $replaces,
        ]);

        return null;
    }

    /**
     * Wait up to $timeout for bytes from the workers, and act on them: a heartbeat, ready or
     * recycle. A signal ends the wait early.
     */
    private function readPipes(float $timeout): void
    {
        $sooner = $this->lastRead + self::ROUND_MIN - self::now();
        if ($this->onlyHeartbeats && $sooner > 0) {
            \usleep((int) ($sooner * 1_000_000));
        }
        $this->lastRead       = self::now();
        $this->onlyHeartbeats = false;
        $read                 = [];
        foreach ($this->workers as $pid => $w) {
            if ($w->pipe) {
                $read[$pid] = $w->pipe;
            }
        }
        if (!$read) {
            \usleep((int) ($timeout * 1_000_000));

            return;
        }
        $write = [];
        foreach ($this->workers as $pid => $w) {
            if ($w->pipe && '' !== $w->out) {
                $write[$pid] = $w->pipe;
            }
        }
        $except = null;
        if (!@\stream_select($read, $write, $except, 0, (int) ($timeout * 1_000_000))) {
            return;
        }
        foreach ($write as $pid => $pipe) {
            $this->flush($this->workers[$pid]);
        }
        $now                  = self::now();
        $this->onlyHeartbeats = true;
        foreach ($read as $pid => $pipe) {
            $w     = $this->workers[$pid];
            $bytes = (string) @\fread($pipe, 65536);
            if ('' === $bytes && \feof($pipe)) {
                \fclose($pipe);
                $w->pipe              = null;
                $this->onlyHeartbeats = false;
                continue;
            }
            $w->lastSeen = $now;
            $w->in .= $bytes;
            $requested = false;
            $bytes     = Topics::parse($w->in, function (string $topic, string $message) use (&$requested, $w) {
                $requested = true; // answered at once, and the next round waits for nothing
                if (Cache::TOPIC === $topic) {
                    $reply = Cache::serve($this->cache, $this->claims, $w->inbox, $message, function (?array $keys) {
                        $this->forgetAll(Topics::frame(Cache::FORGET, \serialize($keys)));
                    });
                    if (null !== $reply) {
                        $this->send($w, Topics::frame(Cache::TOPIC, $reply));
                    }
                } elseif (Inboxes::TOPIC === $topic) {
                    $reply = $this->inboxes->serve($w->inbox, $message, fn (string $topic) => $this->forgetAll(Topics::frame(Inboxes::FORGET, $topic)));
                    if (null !== $reply) {
                        $this->send($w, Topics::frame(Inboxes::TOPIC, $reply));
                    }
                } else {
                    throw new \UnexpectedValueException('A worker sent a frame for an unknown topic');
                }
            });
            $this->onlyHeartbeats = $this->onlyHeartbeats && !$requested && '' === \trim($bytes, '.');
            if (\str_contains($bytes, 'R') && WorkerProcess::STARTING === $w->state) {
                $this->onReady($w);
            }
            // Also from a STARTING worker: its check can come before its 'R'
            $w->recycle = $w->recycle || \str_contains($bytes, 'C');
            $w->served  = $w->served || \str_contains($bytes, 'Q');
            $w->fatal   = $w->fatal || \str_contains($bytes, 'F');
            if (\str_contains($bytes, 'D') && WorkerProcess::DRAINING !== $w->state) {
                // Not asked by the master: an operator's kill, systemd's, or the OOM tooling's. Its
                // slot is free for a new worker, and its exit is a drain, not a crash. When the
                // master was signalled too, the signal went to the whole process group (systemd's
                // KillMode=control-group), and the master is about to stop them all.
                $w->state         = WorkerProcess::DRAINING;
                $w->drainingSince = $now;
                $this->leaveInbox($w);
                if (!$this->stopSignals) {
                    $this->logger->warning('Worker {pid} (slot {slot}) is draining on a SIGTERM the master did not send', ['pid' => $w->pid, 'slot' => $w->slot]);
                }
            }
        }
    }

    private function onReady(WorkerProcess $w): void
    {
        $now        = self::now();
        $w->state   = WorkerProcess::SERVING;
        $w->readyAt = $now;
        $this->logger->info('Worker {pid} (slot {slot}) ready in {s} s', ['pid' => $w->pid, 'slot' => $w->slot, 's' => \round($now - $w->started, 2)]);
        if (!$this->everReady) {
            $this->everReady = true;
            $this->logger->notice($this->serving);
        }
        $old = null !== $w->replaces ? ($this->workers[$w->replaces] ?? null) : null;
        if ($old && WorkerProcess::SERVING === $old->state) {
            $this->drain($old);
            $this->logger->notice('Slot {slot}: worker {new} took over, draining {old} ({why})', [
                'slot' => $w->slot, 'new' => $w->pid, 'old' => $old->pid, 'why' => $old->recycle ? 'recycle' : 'reload',
            ]);
        }
        if (null !== $this->reloadStarted) {
            foreach ($this->workers as $other) {
                if (WorkerProcess::DRAINING !== $other->state && $other->generation < $this->generation) {
                    return;
                }
            }
            $this->logger->notice('Reload complete in {s} s', ['s' => \round($now - $this->reloadStarted, 2)]);
            $this->reloadStarted = null;
        }
    }

    private function reap(): void
    {
        while (($pid = \pcntl_waitpid(-1, $status, \WNOHANG)) > 0) {
            if (isset($this->workers[$pid])) {
                $this->exited($pid, $status);
            } else {
                $this->logger->info('Reaped process {pid}, not a worker: {how}', ['pid' => $pid, 'how' => $this->describe($status, false)]);
            }
        }
    }

    private function exited(int $pid, int $status): void
    {
        $w = $this->workers[$pid];
        unset($this->workers[$pid]);
        if ($w->pipe) {
            // Its last bytes may not have been read yet: a 'Q' right before a request killed it,
            // an 'F' from its shutdown
            $w->in .= (string) @\fread($w->pipe, 1 << 20);
            $bytes     = Topics::parse($w->in, static fn () => null);
            $w->served = $w->served || \str_contains($bytes, 'Q');
            $w->fatal  = $w->fatal || \str_contains($bytes, 'F');
            \fclose($w->pipe);
        }
        $this->leaveInbox($w);
        $this->inboxes->release($w->inbox);
        $now    = self::now();
        $killed = null !== $w->killReason ? ", killed: {$w->killReason}" : '';
        $ctx    = ['pid' => $pid, 'slot' => $w->slot, 'how' => $this->describe($status, $w->fatal), 'up' => self::duration($now - $w->started)];

        if (WorkerProcess::DRAINING === $w->state) {
            if (\pcntl_wifexited($status) && 0 === \pcntl_wexitstatus($status)) {
                $this->logger->info('Worker {pid} (slot {slot}) exited after draining ({how}, up {up})', $ctx);
            } elseif ($w->fatal) {
                // A fatal error, whichever came first: its drain notice or its exit
                $this->logger->error('Worker {pid} (slot {slot}) died: {how}, up {up}' . $killed, $ctx);
            } else {
                $this->logger->warning('Worker {pid} (slot {slot}) ended while draining: {how}' . $killed, $ctx);
            }

            return;
        }

        $this->logger->error('Worker {pid} (slot {slot}) died: {how}, up {up}, ' . ($w->served ? 'had served' : (null !== $w->readyAt ? 'was ready' : 'never became ready')) . $killed, $ctx);
        if (null === $w->readyAt || (!$w->served && $now - $w->readyAt < self::STABLE_AFTER)) {
            $delay = $this->backoff(++$this->failures[$w->slot]);
        } else {
            $this->failures[$w->slot] = 0;
            $delay                    = 0.0;
        }
        $this->nextStart[$w->slot] = $now + $delay;
        $ctx['delay']              = self::seconds($delay);

        if (!$this->everReady && \min($this->failures) > 0) {
            $this->logger->critical('The application failed to start: every worker failed (last: {how}); stopping', $ctx);
            $this->exitCode = \pcntl_wifexited($status) ? \max(1, \pcntl_wexitstatus($status)) : 1;

            return;
        }

        $old = null === $w->readyAt && null !== $w->replaces ? ($this->workers[$w->replaces] ?? null) : null;
        if (null !== $old) {
            // A replacement failed to start; the worker it was to replace serves on, and
            // handover() tries again after the backoff
            $level        = $this->failures[$w->slot] >= self::CRASH_LOOP ? 'critical' : 'error';
            $ctx['count'] = $this->failures[$w->slot];
            if ($old->recycle) {
                $this->logger->log($level, 'Recycle of slot {slot} failed: its new worker {how} before becoming ready ({count} failed starts in a row); the old worker serves on; retrying in {delay} s', $ctx);
            } else {
                $replaced = 0;
                foreach ($this->workers as $other) {
                    $replaced += (int) (WorkerProcess::SERVING === $other->state && $other->generation === $this->generation);
                }
                $this->logger->log($level, 'Reload held up: new worker for slot {slot} {how} before becoming ready ({count} failed starts in a row); the old workers serve on ({k} of {n} slots replaced); retrying in {delay} s', $ctx + ['k' => $replaced, 'n' => $this->numWorkers]);
            }

            return;
        }
        foreach ($this->workers as $other) {
            if ($other->slot === $w->slot && WorkerProcess::DRAINING !== $other->state) {
                return; // its replacement, starting already, takes the slot
            }
        }
        if ($this->failures[$w->slot] >= self::CRASH_LOOP) {
            $this->logger->critical('Slot {slot} is crash-looping: {n} failed starts in a row (last: {how} after {up}); next start in {delay} s', $ctx + ['n' => $this->failures[$w->slot]]);
        } elseif ($delay > 0) {
            $this->logger->warning('Restarting slot {slot} in {delay} s', $ctx);
        }
    }

    /**
     * Kill workers past a deadline: not ready in time, silent (stuck) for longer than the
     * watchdog allows, or draining for longer than the grace period. Also clears the failures
     * of slots whose worker has now been ready long enough.
     */
    private function deadlines(): void
    {
        $now = self::now();
        if ($now - $this->lastDeadlines > self::PAUSED) {
            // The master did not run either, and could not see the heartbeats: after it continues,
            // the first one wakes it before the others arrive
            foreach ($this->workers as $w) {
                $w->lastSeen = $now;
            }
        }
        $this->lastDeadlines = $now;
        $stuck               = [];
        foreach ($this->workers as $w) {
            if (WorkerProcess::STARTING === $w->state && $now - $w->started > self::READY_TIMEOUT) {
                $this->kill($w, 'not ready after ' . self::seconds(self::READY_TIMEOUT) . ' s', 'error');
            } elseif (WorkerProcess::STARTING !== $w->state && $this->watchdog > 0 && $now - $w->lastSeen > $this->watchdog && null === $w->killReason) {
                $silent  = \round($now - $w->lastSeen, 1);
                $stuck[] = [$w, $silent];
                \posix_kill($w->pid, \SIGQUIT);
                $this->logger->error('Worker {pid} (slot {slot}) sent no heartbeat for {s} s: killing it (busy loop or blocking call)', ['pid' => $w->pid, 'slot' => $w->slot, 's' => $silent]);
            } elseif (WorkerProcess::DRAINING === $w->state && $now - $w->drainingSince > $this->grace) {
                $this->kill($w, 'grace expired', 'warning');
            }
            // Only a worker started since the failures proves the slot starts again: not the one
            // a failing replacement was to take over from, which serves on
            if (WorkerProcess::SERVING === $w->state && $this->failures[$w->slot] > 0 && $now - $w->readyAt >= self::STABLE_AFTER
                && !$w->recycle && $w->generation === $this->generation) {
                $this->failures[$w->slot] = 0;
                $this->logger->notice('Slot {slot} recovered', ['slot' => $w->slot]);
            }
        }
        if ($stuck) {
            \usleep((int) (self::REPORT_WAIT * 1_000_000)); // for their SIGQUIT's log line
            foreach ($stuck as [$w, $silent]) {
                $this->kill($w, "watchdog: silent $silent s", null);
            }
        }
    }

    /**
     * Send a frame to every worker that reads its pipe: not to one still starting, which has
     * read nothing yet and has nothing to forget.
     */
    private function forgetAll(string $frame): void
    {
        foreach ($this->workers as $w) {
            if (WorkerProcess::STARTING !== $w->state) {
                $this->send($w, $frame);
            }
        }
    }

    /** A worker that drains or is gone has no subscriptions and no claims: what is published goes to the others. */
    private function leaveInbox(WorkerProcess $w): void
    {
        $this->claims->release($w->inbox);
        $this->inboxes->leave($w->inbox, fn (string $topic) => $this->forgetAll(Topics::frame(Inboxes::FORGET, $topic)));
    }

    /**
     * Queue bytes for a worker, and write what its pipe takes; the rest goes as readPipes()
     * sees the pipe writable.
     */
    private function send(WorkerProcess $w, string $bytes): void
    {
        if (!$w->pipe) {
            return;
        }
        $w->out .= $bytes;
        $this->flush($w);
    }

    private function flush(WorkerProcess $w): void
    {
        $w->out = \substr($w->out, (int) @\fwrite($w->pipe, $w->out));
    }

    /**
     * SIGKILL a worker, once; the reaper handles the rest. Returns whether it was killed now.
     */
    private function kill(WorkerProcess $w, string $reason, ?string $level): bool
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
     * Start a rolling reload: every worker running is replaced by one of the new generation,
     * see handover(). A reload during a reload just replaces the workers again.
     */
    private function reload(string $why): void
    {
        ++$this->generation;
        $this->reloadStarted = self::now();
        $this->reopenLog(); // logrotate renamed it, then sent SIGHUP
        // A fixed deploy starts crash-looping slots at once
        foreach ($this->failures as $slot => $_) {
            $this->failures[$slot]  = 0;
            $this->nextStart[$slot] = 0.0;
        }
        if (\function_exists('opcache_reset')) {
            \opcache_reset();
        }
        $this->logger->notice('Reload requested ({why}): replacing {n} workers one at a time', ['why' => $why, 'n' => $this->numWorkers]);
    }

    /**
     * Reopen the log file, after log rotation renamed it: in the master, and in every worker,
     * which a failed reload may leave serving for long.
     */
    private function reopenLog(): void
    {
        if ($this->logger instanceof Logger) {
            $this->logger->reopen();
        }
        foreach ($this->workers as $w) {
            $this->send($w, 'L');
        }
    }

    /** Ask a worker to drain: by a byte, see the class's docblock. */
    private function drain(WorkerProcess $w): void
    {
        $this->send($w, 'T');
        $w->state         = WorkerProcess::DRAINING;
        $w->drainingSince = self::now();
        $this->leaveInbox($w);
    }

    /**
     * Start the next replacement, for a reload or a recycle, unless one is starting already.
     * The old worker serves until the new one is ready (see onReady()), so each slot always
     * has a listener. Returns the Worker in the new child.
     *
     * A slot whose replacement failed goes first, once its backoff is over: a start that keeps
     * failing is retried in that one slot, not in every slot in turn.
     */
    private function handover(): ?Worker
    {
        foreach ($this->workers as $w) {
            if (WorkerProcess::STARTING === $w->state && null !== $w->replaces) {
                return null;
            }
        }
        $order = fn (WorkerProcess $w) => [-$this->failures[$w->slot], $w->slot];
        $next  = null;
        foreach ($this->workers as $w) {
            if (WorkerProcess::SERVING === $w->state && ($w->recycle || $w->generation < $this->generation)
                && (null === $next || $order($w) < $order($next))) {
                $next = $w;
            }
        }

        return $next && self::now() >= $this->nextStart[$next->slot] ? $this->spawn($next->slot, $next->pid) : null;
    }

    /**
     * Drain every worker within the grace period, then SIGKILL what remains. Never hangs: the
     * master returns by grace + KILL_WAIT at the latest.
     */
    private function stopAll(int $code): int
    {
        $start = self::now();
        $this->logger->notice('Shutting down: draining {n} workers (grace {g} s)', ['n' => \count($this->workers), 'g' => self::seconds($this->grace)]);
        foreach ($this->workers as $w) {
            if (WorkerProcess::DRAINING !== $w->state) {
                $this->drain($w);
            }
        }
        $deadline = $start + $this->grace;
        // The signal that stopped us counts once: one sent right after it, before this loop, too
        $signals  = \min($this->stopSignals, 1);
        while ($this->workers && self::now() < $deadline) {
            $this->readPipes(0.1);
            $this->reap();
            if ($this->stopSignals > $signals) {
                $this->logger->warning('Second signal: killing workers now');
                break;
            }
        }
        if ($this->workers) {
            $this->logger->warning('Grace expired: killing {pids}', ['pids' => \implode(', ', \array_keys($this->workers))]);
            foreach ($this->workers as $w) {
                $w->killReason ??= 'shutdown grace expired';
                \posix_kill($w->pid, \SIGKILL);
            }
            $until = self::now() + self::KILL_WAIT;
            while ($this->workers && self::now() < $until) {
                $this->reap();
                \usleep(10_000);
            }
            if ($this->workers) {
                $this->logger->emergency('Processes {pids} did not die after SIGKILL', ['pids' => \implode(', ', \array_keys($this->workers))]);
            }
        }
        $this->logger->notice('Stopped in {s} s', ['s' => \round(self::now() - $start, 2)]);

        return $code;
    }

    /**
     * The first changed, added or deleted file since the workers were started, once the
     * change has stayed the same for one scan: an editor or a deploy writing several files
     * causes one reload, 1 to 2 seconds after the last write.
     */
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
        $seen              = $this->scanSeen;
        $this->scanSeen    = $now;
        $this->scanPending = null;
        foreach ($now as $file => $stat) {
            if (($seen[$file] ?? null) !== $stat) {
                return $file;
            }
        }

        return (string) \array_key_first(\array_diff_key($seen, $now));
    }

    /**
     * The PHP files below the monitored directory, with their mtime and size, and where the
     * directory resolves to (a deploy may swap a symlink to a new release). Directories named
     * vendor are skipped, as stat-ing thousands of vendor files every second is costly;
     * vendor/composer/installed.php still tells when `composer install` or `update` ran. Names
     * starting with a dot are skipped too: editors' lock and backup files, such as Emacs's
     * `.#app.php`, a symlink to nowhere.
     *
     * A directory that can't be read, a file removed between the listing and its stat, or a
     * symlink to nowhere is left out: the master must never die of what happens to the files.
     *
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
            // The directory itself can't be read: as if it had no files
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

    /**
     * @param bool $fatal the worker said a PHP fatal error ends it; a signal then came during
     *                    its shutdown, when PHP no longer handles signals
     */
    private function describe(int $status, bool $fatal): string
    {
        if (\pcntl_wifexited($status)) {
            $code = \pcntl_wexitstatus($status);

            // Else the application's own exit(255)
            return "exit $code" . (255 === $code && $fatal ? ' (PHP fatal error, e.g. memory_limit; see log)' : '');
        }
        $signal = \pcntl_wtermsig($status);

        return "signal $signal (" . System::signalName($signal) . ')' . ($fatal ? ' during its shutdown after a PHP fatal error (e.g. memory_limit; see log)' : '');
    }

    /** Seconds on the monotonic clock. */
    private static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    /** "0.5", "1", "30": seconds for the log. */
    private static function seconds(float $seconds): string
    {
        return \rtrim(\rtrim(\sprintf('%.1f', $seconds), '0'), '.');
    }

    /** "0.4 s" or "3m12s". */
    private static function duration(float $seconds): string
    {
        return $seconds < 60 ? \sprintf('%.1f s', $seconds) : \sprintf('%dm%02ds', (int) ($seconds / 60), (int) $seconds % 60);
    }
}
