<?php

namespace Swerve\Util;

use Closure;
use Composer\Autoload\ClassLoader;
use phasync;
use Psr\Log\LoggerInterface;

/**
 * A worker process's side of the supervision, see Cluster: tells the master it is alive
 * and ready, asks to be recycled when it leaks, and drains its servers when the master asks
 * ('T' over the socket pair), on SIGTERM from outside, or when the master died.
 *
 * SIGINT and SIGHUP are ignored: Ctrl+C and a closing terminal signal the whole process
 * group, and only the master decides what happens to the workers. SIGQUIT logs what the
 * worker is doing: the requests in flight, and where the code runs.
 */
final class Worker
{
    /**
     * How soon the master's death is acted on, and how often the heartbeat goes out: often
     * enough that a busy worker is never late by a whole watchdog timeout.
     */
    public const TICK = 0.25;
    /** The application file failed to load, or returned something that can't be served. */
    public const EXIT_BAD_APP = 2;

    /** Bytes of memory above which the worker asks to be recycled, jitter applied; 0 = off. */
    public int $maxMemory = 0;
    /** Requests after which the worker asks to be recycled, jitter applied; 0 = off. */
    public int $maxRequests = 0;

    private bool $stopRequested = false;
    private bool $draining = false;
    private float $drainStarted = 0.0;
    /** When a drain that has not finished drops its connections, see tick(). */
    private float $deadline = \PHP_FLOAT_MAX;
    private bool $recycleSent = false;
    /** Requests started. */
    private int $requests = 0;
    /** Servers whose run() has not returned yet. */
    private int $running = 0;
    /** @var Closure[] each server's drain() */
    private array $drains = [];
    private bool $ticking = false;
    /**
     * The requests in flight, each as a function that describes it ("GET /path"), for the log
     * of a fatal error or of a stall.
     *
     * @var array<int, Closure(): string>
     */
    private array $inFlight = [];
    /** @var resource the SIGTERM handler writes to it, see awaitTerm() */
    private $wakeWrite;
    /** @var resource awaitTerm() waits on it */
    private $wake;
    /** The event loop's last tick: the worker's start until the first, see the SIGQUIT dump. */
    private float $lastTick;
    /** Bytes for the master that the pipe did not take yet, see send(). */
    private string $out = '';
    /** Seconds without a tick after which SIGALRM checks whether the master is alive; 0 = never. */
    private readonly int $stallCheck;

    /**
     * @param resource $pipe     the worker's end of the socket pair to the master
     * @param float    $watchdog the master's watchdog timeout, see the SIGALRM handler; 0 = off
     */
    public function __construct(
        public readonly int $slot,
        private $pipe,
        private readonly int $masterPid,
        private readonly float $grace,
        float $watchdog,
        public readonly LoggerInterface $logger,
    ) {
        $this->lastTick = \microtime(true);
        [$this->wake, $this->wakeWrite] = System::socketPair();
        \stream_set_blocking($this->wake, false);
        \stream_set_blocking($this->wakeWrite, false);
        \pcntl_signal(\SIGTERM, function () {
            $this->stopRequested = true;
            @\fwrite($this->wakeWrite, '!'); // a retired worker must stop accepting now, not a tick later
        });
        \pcntl_signal(\SIGINT, \SIG_IGN);
        \pcntl_signal(\SIGHUP, \SIG_IGN);
        // The master's meanings of these (log reopen, reload) are not a worker's
        \pcntl_signal(\SIGUSR1, \SIG_DFL);
        \pcntl_signal(\SIGUSR2, \SIG_DFL);
        // The watchdog sends SIGQUIT before its SIGKILL, so that the log says what the worker was
        // stuck in: async handlers run during a busy loop, and a blocking call ends early
        \pcntl_signal(\SIGQUIT, function () {
            $at = [];
            foreach (\array_slice(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS), 0, 10) as $frame) {
                if (isset($frame['file'])) {
                    $at[] = $frame['file'] . ':' . $frame['line'];
                }
            }
            $this->logger->warning('Silent for {s} s; in flight: {requests}; at {at}', ['s' => \round(\microtime(true) - $this->lastTick, 1), 'requests' => $this->describeInFlight(), 'at' => \implode(' < ', $at)]);
        });
        // The master's watchdog can't stop a worker whose event loop is stuck once the master is
        // gone, and such a worker would keep its listener, taking a share of the connections of
        // the next swerve on the address and never answering them. heartbeat() re-arms the alarm,
        // so it only goes off when the loop stalled; being async, the handler still runs.
        //
        // A signal cuts short the application's blocking calls (sleep(), stream_select()), so the
        // alarm comes a second after the watchdog's timeout, when a live master has killed the
        // worker already: while the master lives, it never goes off. With the watchdog off,
        // stalls are allowed, and there is no alarm once the worker serves; the application may
        // use SIGALRM then.
        //
        // Until the worker serves, the alarm comes a second after READY_TIMEOUT instead, past
        // which the master kills a worker not ready: one stuck loading the application (a
        // database connect without a timeout) must not outlive a master that died meanwhile.
        $this->stallCheck = $watchdog > 0 ? (int) \ceil($watchdog) + 1 : 0;
        \pcntl_signal(\SIGALRM, function () {
            if (\posix_getppid() !== $this->masterPid) {
                if ($this->ticking) {
                    $this->logger->critical('Master process died while the event loop was stuck for {s} s; exiting', ['s' => \round(\microtime(true) - $this->lastTick, 1)]);
                } else {
                    $this->logger->critical('Master process died while the application was loading; exiting');
                }
                \posix_kill(\getmypid(), \SIGKILL); // stuck: nothing else is sure to end it
            }
            \pcntl_alarm($this->stallCheck);
        });
        \pcntl_alarm((int) Cluster::READY_TIMEOUT + 1);
        // PHP's shutdown after a fatal error (memory_limit) puts the default signal actions back
        // before it is done: a SIGTERM from outside in that time kills the process, and the exit
        // status, which tells of the fatal error, is lost. So the master is told. PHP's own
        // message names neither this worker nor the request that failed; after memory_limit,
        // writing that takes memory too.
        \register_shutdown_function(function () {
            $error = \error_get_last();
            if (($error['type'] ?? 0) & (\E_ERROR | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_PARSE)) {
                // Blocking: the process is ending, and a frame half sent must not swallow the 'F'
                \stream_set_blocking($this->pipe, true);
                @\fwrite($this->pipe, $this->out . 'F');
                \ini_set('memory_limit', '-1');
                $this->logger->critical('PHP fatal error: {message} in {file}:{line}; in flight: {requests}', $error + ['requests' => $this->describeInFlight()]);
            }
        });
        // Blocked by the master around the fork: a signal that came meanwhile arrives now
        \pcntl_sigprocmask(\SIG_UNBLOCK, [\SIGTERM, \SIGINT, \SIGHUP, \SIGUSR1, \SIGUSR2, \SIGQUIT]);
        \stream_set_blocking($pipe, false);
        Topics::$toMaster = fn (string $topic, string $message) => $this->send(Topics::frame($topic, $message));
    }

    /**
     * Re-read Composer's class map and PSR-4 prefixes, which the master loaded at its start:
     * after `composer dump-autoload` a reload would otherwise miss new classes.
     */
    public static function refreshAutoloader(): void
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendorDir => $loader) {
            $loader->addClassMap(require "$vendorDir/composer/autoload_classmap.php");
            foreach (require "$vendorDir/composer/autoload_psr4.php" as $prefix => $paths) {
                $loader->setPsr4($prefix, $paths);
            }
        }
    }

    /**
     * Set the recycling limits, each with up to 10 % (memory) or 20 % (requests) jitter, so
     * that workers started together don't all recycle together.
     *
     * @param string $maxMemory bytes (optionally K, M or G), P% of memory_limit, or 0 for off
     * @param int    $maxRequests 0 for off
     */
    public function setLimits(string $maxMemory, int $maxRequests): void
    {
        $ini     = (string) \ini_get('memory_limit');
        $limit   = \ini_parse_quantity($ini);
        $percent = \str_ends_with($maxMemory, '%');
        if ($percent) {
            $soft = $limit > 0 ? \intdiv($limit * (int) $maxMemory, 100) : 0;
        } else {
            $soft = \ini_parse_quantity($maxMemory);
        }
        $off = $percent && $limit <= 0 && $soft < 1 && (int) $maxMemory > 0
            ? "memory recycling off (memory_limit is -1, so --max-memory=$maxMemory is no limit; give a size such as 512M to enable)"
            : 'memory recycling off';
        if ($limit > 0 && $soft >= $limit) {
            // PHP's fatal error would always come first, ending the requests in flight
            $this->logger->warning('--max-memory={max} is not below memory_limit {ini}, so a leaking worker dies of PHP\'s fatal error instead: memory recycling off; lower --max-memory', ['max' => $maxMemory, 'ini' => $ini]);
            $soft = 0;
            $off  = 'memory recycling off (--max-memory is not below memory_limit)';
        }
        // Drawn here, in the forked worker: random_int() doesn't share state across forks
        $this->maxMemory   = \intdiv($soft * \random_int(900, 1000), 1000);
        $this->maxRequests = $maxRequests ? $maxRequests + \random_int(0, \intdiv($maxRequests, 5)) : 0;
        \gc_collect_cycles();
        if ($this->maxMemory && ($usage = \memory_get_usage(true)) > $this->maxMemory) {
            // Every worker would ask to be recycled at once, its replacement too
            $this->logger->warning('Memory after loading the application, {usage} MiB, is above the recycling limit of {limit} MiB: memory recycling is off; raise --max-memory', [
                'usage' => \round($usage / 1048576, 1), 'limit' => \round($this->maxMemory / 1048576, 1),
            ]);
            $this->maxMemory = 0;
            $off             = 'memory recycling off (the limit is below the memory after loading)';
        }
        $this->logger->info('Worker {pid} slot {slot}: {memory}, max requests {requests}', [
            'pid'      => \getmypid(),
            'slot'     => $this->slot,
            'memory'   => $this->maxMemory
                ? \sprintf('memory recycling at %.1f MiB (%s of memory_limit %s)', $this->maxMemory / 1048576, $maxMemory, $ini)
                : $off,
            'requests' => $this->maxRequests ?: 'off',
        ]);
    }

    /**
     * Run a server in a coroutine: $run serves until drained and returns, $drain makes it stop
     * accepting and finish. The worker exits once every server returned. A server that fails,
     * or returns without being drained, ends the worker, so that the master starts another.
     */
    public function serve(Closure $run, Closure $drain): void
    {
        $this->drains[] = $drain;
        ++$this->running;
        phasync::go(function () use ($run) {
            try {
                $run();
            } catch (\Throwable $e) {
                $this->logger->critical('{exception}', ['exception' => $e]);
                exit(1);
            }
            if (!$this->draining) {
                $this->logger->critical('A server stopped without being drained');
                exit(1);
            }
            if (0 === --$this->running) {
                $this->logger->info('Drained in {s} s, exiting', ['s' => \round(\microtime(true) - $this->drainStarted, 2)]);
                exit(0);
            }
        });
        if (!$this->ticking) {
            $this->ticking = true;
            if (!$this->stallCheck) {
                \pcntl_alarm(0); // the one for loading, see the constructor
            }
            phasync::go($this->tick(...));
            phasync::go($this->awaitTerm(...));
            phasync::go($this->awaitMaster(...));
        }
    }

    /** Tell the master this worker listens: it may now retire the worker this one replaces. */
    public function ready(): void
    {
        $this->send('R');
    }

    /**
     * Called when a request starts; returns its id for requestDone(). The first one tells the
     * master this worker serves: when it dies from then on, a request killed it, not a failed
     * start, see Cluster::exited().
     *
     * @param Closure(): string $describe the request for the log, such as "GET /path"
     */
    public function requestStarted(Closure $describe): int
    {
        $this->inFlight[++$this->requests] = $describe;
        if (1 === $this->requests) {
            $this->send('Q');
        }
        $this->heartbeat();

        return $this->requests;
    }

    /** Called after every request: recycle after so many requests, or when over the memory limit. */
    public function requestDone(int $id): void
    {
        unset($this->inFlight[$id]);
        $this->heartbeat();
        if ($this->maxRequests && $this->requests >= $this->maxRequests) {
            $this->recycle("served {$this->requests} requests");
        } else {
            $this->checkMemory();
        }
    }

    private function checkMemory(): void
    {
        if ($this->recycleSent || !$this->maxMemory || \memory_get_usage(true) <= $this->maxMemory) {
            return;
        }
        // phasync disables the cycle collector while it runs: what cycles hold isn't a leak yet
        \gc_collect_cycles();
        if (($usage = \memory_get_usage(true)) > $this->maxMemory) {
            $this->recycle(\sprintf('memory %.1f MiB over limit %.1f MiB after gc (peak %.1f MiB, %d requests)',
                $usage / 1048576, $this->maxMemory / 1048576, \memory_get_peak_usage(true) / 1048576, $this->requests));
        }
    }

    /**
     * Ask the master for a replacement, once. This worker serves on until the replacement
     * listens and the master asks it to drain.
     */
    private function recycle(string $why): void
    {
        if ($this->recycleSent) {
            return;
        }
        $this->recycleSent = true;
        $this->logger->notice('Recycling: {why}; asking the master for a replacement', ['why' => $why]);
        $this->send('C');
    }

    /**
     * The heartbeat, acting on the master's death, and the drain deadline.
     *
     * The heartbeat comes from a coroutine in the event loop, not from a timer signal: async
     * signal handlers run even during a busy loop, and would hide the stall the master's
     * watchdog is there to catch. A blocking call stops this coroutine the same way.
     *
     * The worker ends with exit() rather than by returning from phasync::run(): application
     * timers or background coroutines could keep the event loop running.
     */
    private function tick(): void
    {
        while (true) {
            $now = \microtime(true);
            $this->heartbeat();
            if (!$this->draining) {
                $this->checkMemory(); // leaks while idle, or in long responses such as SSE
            }
            if (!$this->draining && \posix_getppid() !== $this->masterPid) {
                $this->logger->critical('Master process died; draining');
                $this->drain('the master died');
            }
            if ($now >= $this->deadline) {
                $this->logger->warning('Drain deadline reached after {s} s; dropping open connections', ['s' => \round($now - $this->drainStarted, 2)]);
                exit(0);
            }
            phasync::sleep(self::TICK);
        }
    }

    /**
     * Tell the master the event loop runs, at most once a tick: from tick(), and as requests
     * start and end. Requests that each block (a synchronous database query) or compute for
     * less than the watchdog's timeout, run one after another, can hold off tick()'s timer for
     * longer than that, while the worker is busy, not stuck.
     */
    private function heartbeat(): void
    {
        $now = \microtime(true);
        if ($now - $this->lastTick < self::TICK) {
            return;
        }
        $this->lastTick = $now;
        if ($this->stallCheck) {
            \pcntl_alarm($this->stallCheck);
        }
        $this->send('.');
    }

    /**
     * Act on what the master sends: 'T' to drain, 'L' to reopen the log file after log
     * rotation, and published messages, see Topics. Bytes, not signals: a signal cuts short the
     * blocking calls (sleep(), stream_select()) of the requests in flight, which a drain is there
     * to let finish. Ends at the end of the pipe, when the master died; tick() acts on that.
     */
    private function awaitMaster(): void
    {
        $buffer = '';
        while (true) {
            $bytes = (string) \fread(phasync::readable($this->pipe, \PHP_FLOAT_MAX), 65536);
            if ('' === $bytes && \feof($this->pipe)) {
                return;
            }
            $buffer .= $bytes;
            $status = Topics::parse($buffer, static fn (string $topic, string $message) => Topics::deliver($topic, $message));
            if (\str_contains($status, 'L') && $this->logger instanceof Logger) {
                $this->logger->reopen();
            }
            if (\str_contains($status, 'T') && !$this->draining) {
                $this->drain('the master asked');
            }
        }
    }

    /**
     * Send bytes to the master, in order: a status byte, or a published message's frame. What
     * the pipe doesn't take at once (a slow master, a large message) is sent by a coroutine as
     * the pipe takes it, never blocking the caller.
     */
    private function send(string $bytes): void
    {
        if ('' !== $this->out) {
            $this->out .= $bytes;

            return;
        }
        $written = @\fwrite($this->pipe, $bytes);
        if (false === $written || \strlen($bytes) === $written) {
            return; // false: the master is gone, and tick() acts on that
        }
        $this->out = \substr($bytes, $written);
        phasync::go(function () {
            while ('' !== $this->out) {
                $written = @\fwrite(phasync::writable($this->pipe, \PHP_FLOAT_MAX), $this->out);
                if (false === $written) {
                    $this->out = '';

                    return;
                }
                $this->out = \substr($this->out, $written);
            }
        });
    }

    /** "GET /a, GET /b", or "none". */
    private function describeInFlight(): string
    {
        return \implode(', ', \array_map(static fn (Closure $describe) => $describe(), $this->inFlight)) ?: 'none';
    }

    /**
     * Drain on SIGTERM from outside (an operator, systemd) at once, not a tick later. The
     * SIGTERM handler can't drain itself: it may run in the middle of anything, the event loop
     * included.
     */
    private function awaitTerm(): void
    {
        while (!$this->draining) {
            phasync::readable($this->wake, \PHP_FLOAT_MAX);
            \fread($this->wake, 1024);
            if ($this->stopRequested && !$this->draining) {
                $this->drain('SIGTERM');
            }
        }
    }

    /**
     * Each server's run() returns once drained, and the last one exits, see serve().
     */
    private function drain(string $why): void
    {
        $this->draining     = true;
        $this->drainStarted = \microtime(true);
        $this->deadline     = $this->drainStarted + \max($this->grace - 1.0, $this->grace / 2);
        // Asked by the master, the master logs it once for all; anything else is news
        $this->logger->log('the master asked' === $why ? 'info' : 'notice', 'Draining ({why})', ['why' => $why]);
        $this->send('D'); // the master may not know: a SIGTERM from someone else, or its death
        Topics::drain(); // ends the long responses fed by subscriptions
        foreach ($this->drains as $drain) {
            $drain();
        }
    }
}
