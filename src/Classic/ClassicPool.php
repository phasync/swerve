<?php

namespace Swerve\Classic;

use phasync;
use phasync\Util\Process;

use function phasync\Net\dial;

/**
 * A pool of `php -S` processes serving one document root and front controller, grown lazily: a
 * new process starts only when {@see ClassicPool::acquire()} finds no idle one, up to `$max`.
 * No pre-forking, no pre-started processes. A process that sits idle for
 * {@see ClassicPool::IDLE} seconds exits, reaped by a sweep started once, at construction.
 *
 * The sweep is one coroutine for the pool's whole life, not one `phasync::go()` per release():
 * each HTTP connection runs its requests in a `Scope` of their own (see HttpConnection::serve()),
 * which joins every coroutine started inside it before the connection can read the next request —
 * a per-release reap timer started there would make every request on a kept-alive connection but
 * the first wait out that timer (here, up to IDLE seconds) before the next one is even read. The
 * pool itself is constructed before any connection exists, so a coroutine started here belongs to
 * the worker's own frame instead, and release() only timestamps.
 *
 * One swerve worker owns a pool (classic mode runs with exactly one worker; see bin/swerve.php),
 * so acquire() and release() are only ever called from coroutines of that one process — nothing
 * here needs a lock.
 *
 * ```php
 * $pool = new ClassicPool($docroot, $router, max: 16);
 * $process = $pool->acquire();   // starts one if every slot is free and none is idle
 * // ... forward one request to 127.0.0.1:$process->port ...
 * $pool->release($process);
 * ```
 *
 * @internal
 */
final class ClassicPool
{
    /** Seconds a `php -S` process may sit idle (no request) before it exits. */
    private const IDLE = 10.0;

    /** How often the sweep looks for an idle process past {@see ClassicPool::IDLE}. */
    private const SWEEP = 1.0;

    /** Seconds to wait for a freshly started process to accept connections. */
    private const START_TIMEOUT = 5.0;

    /** @var list<ClassicProcess> idle processes; the last entry was released most recently */
    private array $idle = [];

    /** processes started and not yet reaped (idle or busy) */
    private int $active = 0;

    /** @var \SplQueue<phasync\WriteChannelInterface> acquire() calls waiting for a release */
    private \SplQueue $waiters;

    public function __construct(
        private readonly string $docroot,
        private readonly string $router,
        private readonly int $max,
    ) {
        $this->waiters = new \SplQueue();
        phasync::go($this->sweep(...));
    }

    /** Reaps idle processes past {@see ClassicPool::IDLE}; one coroutine for the pool's whole life. */
    private function sweep(): void
    {
        while (true) {
            phasync::sleep(self::SWEEP);
            $now = \microtime(true);
            foreach ($this->idle as $i => $p) {
                if ($now - $p->releasedAt >= self::IDLE) {
                    unset($this->idle[$i]);
                    $p->stop();
                    --$this->active;
                }
            }
            $this->idle = \array_values($this->idle);
        }
    }

    /**
     * An idle process, or a freshly started one, or waits for a release once `$max` are active.
     *
     * @throws \RuntimeException when a new process fails to start
     */
    public function acquire(): ClassicProcess
    {
        while ([] !== $this->idle) {
            $p       = \array_pop($this->idle);
            $p->idle = false;
            if ($p->isRunning()) {
                return $p;
            }
            --$this->active; // died while idle; the sweep will simply never find it in $this->idle
        }
        if ($this->active < $this->max) {
            ++$this->active;
            try {
                return $this->start();
            } catch (\Throwable $e) {
                --$this->active;
                throw $e;
            }
        }
        phasync::channel($read, $write, 0);
        $read->activate(); // this coroutine waits alone; the writer is a release() far later
        $this->waiters->enqueue($write);
        try {
            return $read->read();
        } catch (\Throwable $e) {
            // Abandoned (the client went away while queued): close it and drop it from the
            // queue now, rather than leave release() to find it and hand a process to nobody —
            // an unbuffered write() waits for a reader that will never come, which would freeze
            // every future release() behind it (the whole single-worker pool, since nothing
            // after it in the queue could get a process either).
            $write->close();
            $kept = new \SplQueue();
            foreach ($this->waiters as $w) {
                if ($w !== $write) {
                    $kept->enqueue($w);
                }
            }
            $this->waiters = $kept;

            throw $e;
        }
    }

    /** Gives a process back: to a waiting acquire() at once, else idle with its reap timer armed. */
    public function release(ClassicProcess $p): void
    {
        if (!$p->isRunning()) {
            --$this->active;

            return;
        }
        // A waiter whose acquire() already gave up (see the catch in acquire()) is closed; skip
        // it rather than block forever handing a process to a reader that will never come. This
        // is the belt to acquire()'s suspenders: it alone keeps release() from ever hanging,
        // whether or not a given abandonment was also removed from the queue in time.
        while (!$this->waiters->isEmpty()) {
            $write = $this->waiters->dequeue();
            if ($write->isClosed()) {
                continue;
            }
            try {
                $write->write($p);
            } catch (\Throwable) {
                continue;
            }

            return;
        }
        $p->idle       = true;
        $p->releasedAt = \microtime(true);
        $this->idle[]  = $p;
    }

    /** Processes started and not yet reaped (idle and busy); for logging. */
    public function size(): int
    {
        return $this->active;
    }

    /** Stops every process; for a worker shutdown. */
    public function stopAll(): void
    {
        foreach ($this->idle as $p) {
            $p->stop();
        }
        $this->idle   = [];
        $this->active = 0;
    }

    private function start(): ClassicProcess
    {
        $port    = self::freePort();
        $prepend = __DIR__ . '/prepend.php';
        $args    = [
            '-d', "auto_prepend_file=$prepend",
            '-d', 'display_errors=1',
            ...ClassicIni::flags(),
            '-S', "127.0.0.1:$port",
            '-t', $this->docroot,
            $this->router,
        ];
        $process  = Process::run(\PHP_BINARY, $args, $this->docroot);
        $deadline = \microtime(true) + self::START_TIMEOUT;
        while (true) {
            try {
                // Kept, not closed: the readiness probe doubles as the connection ready for the
                // process's first request (see ClassicProcess::$conn).
                $conn = dial("tcp://127.0.0.1:$port", 0.05);
                break;
            } catch (\Throwable $e) {
                if (!$process->isRunning()) {
                    throw new \RuntimeException("classic: php -S on 127.0.0.1:$port exited before it accepted a connection (exit {$process->getExitCode()})");
                }
                if (\microtime(true) >= $deadline) {
                    $process->stop();
                    throw new \RuntimeException("classic: php -S on 127.0.0.1:$port did not accept a connection within " . self::START_TIMEOUT . 's');
                }
                phasync::sleep(0.01);
            }
        }

        return new ClassicProcess($process, $port, $conn);
    }

    /** A free TCP port on 127.0.0.1: bind, read the port, release it (a benign race, as tests do). */
    private static function freePort(): int
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        $addr  = \stream_socket_get_name($probe, false);
        \fclose($probe);

        return (int) \substr($addr, \strrpos($addr, ':') + 1);
    }
}
