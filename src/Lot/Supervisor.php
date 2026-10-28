<?php

namespace Swerve\Lot;

use phasync;
use phasync\ext;
use Psr\Log\LoggerInterface;
use Swerve\Util\SharedLog;

/**
 * The master's processes: a worker in each slot, and WebSocket workers as they are needed, each
 * forked from the master (so they share its opcache and APCu memory) and run with run_next().
 * A worker that exits is replaced; one that fails within a second of its start is replaced a
 * second later, not in a tight loop.
 *
 * Signals, after PM2 and Node's cluster:
 *
 * - SIGTERM or SIGINT: stop. The listener closes; the workers finish the requests they serve,
 *   then their other coroutines (WebSockets, event streams) get a WorkerStoppingException
 *   (Shutdown: a WebSocket closes with 1001). What still runs after `$grace` seconds is killed.
 *   A second SIGTERM or SIGINT kills at once.
 * - SIGHUP: reload. Every slot starts a worker on the code as it is now (the opcache forgets
 *   what it compiled); once it asks for its first request, the old worker of the slot stops as
 *   above, with the reason Reload (a WebSocket closes with 1012: reconnect). A new worker that
 *   fails to start leaves the old one serving until a later one starts. The WebSocket workers
 *   stop with Reload; new ones start as WebSockets need them.
 *
 * With `$watch`, a change to a PHP file below that directory reloads (vendor/ and dot-names
 * left out; vendor/composer/installed.php tells when Composer ran).
 *
 * @internal
 */
final class Supervisor
{
    /** Seconds between scans for changed files. */
    private const WATCH_EVERY = 0.5;

    /** @var array<int, int> the process of each slot, which serves or is starting */
    private array $slots = [];

    /** @var array<int, resource> the channel to each worker process, by pid */
    private array $channels = [];

    /** @var array<int, resource> the control socket of each WebSocket worker that is not stopping, by pid */
    private array $webSocketWorkers = [];

    /** @var array<int, true> every child process, by pid */
    private array $children = [];

    private bool $stopping = false;

    /** Raised as a child exits. */
    private object $exited;

    /**
     * @param resource $out          stdout and stderr of the children
     * @param resource $toWebSockets the end the workers write moved WebSockets to (their fd 4)
     * @param resource $webSockets   the end the WebSocket workers read them from (their fd 3)
     */
    public function __construct(
        private readonly Lot $lot,
        private readonly LoggerInterface $logger,
        private readonly int $workers,
        private readonly string $inc,
        private $out,
        private readonly string $workDir,
        private $toWebSockets,
        private $webSockets,
        private readonly float $grace,
        private readonly ?string $watch,
    ) {
        $this->exited = new \stdClass();
    }

    /** Run until stopped and every child is gone. */
    public function run(): void
    {
        phasync::go($this->lot->run(...));
        phasync::service($this->webSocketWorkersOnDemand(...));
        for ($n = 0; $n < $this->workers; ++$n) {
            $this->startWorker($n);
        }
        if (null !== $this->watch) {
            phasync::service($this->watch(...));
        }
        phasync::service(function (): void {
            while (true) {
                phasync::signal(\SIGHUP);
                $this->logger->notice('Reloading (SIGHUP)');
                $this->reload();
            }
        });
        phasync::signal([\SIGTERM, \SIGINT]);
        $this->stop();
    }

    private function stop(): void
    {
        $this->stopping = true;
        $this->lot->shutdown();
        foreach ($this->webSocketWorkers as $control) {
            $this->tell($control, 'S');
        }
        $this->webSocketWorkers = [];
        $kill = phasync::go(function (): void {
            try {
                phasync::signal([\SIGTERM, \SIGINT], $this->grace);
                $this->logger->warning('Stopping at once (a second signal)');
            } catch (phasync\TimeoutException) {
                $this->logger->warning('{n} processes still ran after the grace period ({grace} s): killed', ['n' => \count($this->children), 'grace' => $this->grace]);
            }
            foreach ($this->children as $pid => $_) {
                \posix_kill($pid, \SIGKILL);
            }
        });
        while ([] !== $this->children) {
            phasync::awaitFlag($this->exited);
        }
        phasync::cancel($kill);
    }

    private function reload(): void
    {
        if (\function_exists('opcache_get_status') && \is_array($status = \opcache_get_status(true))) {
            // Nothing checks timestamps: forget every script, so the new workers compile the code
            // as it is now; the old ones keep what they loaded
            foreach ($status['scripts'] ?? [] as $path => $_) {
                \opcache_invalidate($path, true);
            }
        }
        foreach ($this->webSocketWorkers as $control) {
            $this->tell($control, 'L');
        }
        $this->webSocketWorkers = [];
        $started = \microtime(true);
        $waiting = \count($this->slots);
        foreach ($this->slots as $n => $old) {
            $this->startWorker($n, function () use ($old, $started, &$waiting): void {
                if (isset($this->channels[$old])) {
                    $this->lot->stop($this->channels[$old], 'L');
                }
                if (0 === --$waiting) {
                    $this->logger->notice('Reload complete in {s} s', ['s' => \round(\microtime(true) - $started, 2)]);
                }
            });
        }
    }

    /**
     * Start a worker in slot $n, replacing whatever was there; $onReady when it first asks for a
     * request.
     */
    private function startWorker(int $n, ?\Closure $onReady = null): void
    {
        [$master, $channel] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);
        $pid                = $this->fork("$this->inc/worker.php", [$channel, $this->toWebSockets]);
        \fclose($channel);
        $this->slots[$n]      = $pid;
        $this->channels[$pid] = $master;
        $started              = \microtime(true);
        phasync::go(function () use ($master, $onReady): void {
            $this->lot->serve($master, $onReady); // until the worker is gone
            \fclose($master);
        });
        phasync::go(function () use ($n, $pid, $started): void {
            $status = $this->waitFor($pid);
            unset($this->channels[$pid]);
            if ($this->stopping || $this->slots[$n] !== $pid) {
                return; // stopped, or replaced by a reload
            }
            $this->logger->warning('Worker {n} exited ({status}); starting another', ['n' => $n, 'status' => $status]);
            if (\microtime(true) - $started < 1) {
                phasync::sleep(1); // failing at once: not in a tight loop
            }
            if (!$this->stopping && $this->slots[$n] === $pid) {
                $this->startWorker($n);
            }
        });
    }

    /**
     * WebSocket workers on demand, which read the moved WebSockets in turn: the first when a
     * WebSocket first waits there, another whenever one waits there unread for 10 ms (those
     * running are busy), up to one per worker. Only watches the end; never reads it.
     */
    private function webSocketWorkersOnDemand(): void
    {
        while (!$this->stopping) {
            phasync::readable($this->webSockets);
            if ([] !== $this->webSocketWorkers) {
                phasync::sleep(0.01);
                $waiting = [$this->webSockets];
                $none    = null;
                if (0 === \stream_select($waiting, $none, $none, 0)) {
                    continue; // taken meanwhile
                }
            }
            if ($this->stopping || \count($this->webSocketWorkers) >= $this->workers) {
                phasync::sleep(0.5);

                continue;
            }
            [$master, $control] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);
            $pid                = $this->fork("$this->inc/websocket-worker.php", [$this->webSockets, $control]);
            \fclose($control);
            $this->webSocketWorkers[$pid] = $master;
            phasync::go(function () use ($pid, $master): void {
                $status = $this->waitFor($pid);
                \fclose($master);
                if (isset($this->webSocketWorkers[$pid])) {
                    unset($this->webSocketWorkers[$pid]);
                    $this->logger->warning('A WebSocket worker exited ({status})', ['status' => $status]);
                }
            });
            phasync::sleep(0.5); // its start: until it reads, what waits is no sign of busy ones
        }
    }

    /** Reload when a PHP file below $watch changes, and stays so for a scan. */
    private function watch(): void
    {
        $seen = $pending = $this->snapshot();
        while (!$this->stopping) {
            phasync::sleep(self::WATCH_EVERY);
            $now = $this->snapshot();
            if ($now === $seen || $now !== $pending) {
                $pending = $now; // unchanged, or still changing (a deploy, an editor saving)

                continue;
            }
            $changed = \array_key_first(\array_diff_assoc($now, $seen) ?: \array_diff_key($seen, $now));
            $seen    = $now;
            $this->logger->notice('{file} changed: reloading', ['file' => $changed]);
            $this->reload();
        }
    }

    /**
     * The PHP files below $watch, with their mtime and size. Directories named vendor and names
     * starting with a dot (editors' lock and backup files) are left out; a file that can't be
     * read is left out too, as the master must never die of what happens to the files.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        \clearstatcache();
        $files = [];
        try {
            $dirs = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->watch, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => !\str_starts_with($f->getFilename(), '.') && (!$f->isDir() || 'vendor' !== $f->getFilename()),
            );
            foreach (new \RecursiveIteratorIterator($dirs, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $file) {
                if ('php' === $file->getExtension() && false !== ($stat = @\stat($file->getPathname()))) {
                    $files[$file->getPathname()] = $stat['mtime'] . ':' . $stat['size'];
                }
            }
        } catch (\UnexpectedValueException) {
            // the directory itself can't be read: as if it had no files
        }
        $installed = "$this->watch/vendor/composer/installed.php";
        if (false !== ($stat = @\stat($installed))) {
            $files[$installed] = $stat['mtime'] . ':' . $stat['size'];
        }

        return $files;
    }

    /**
     * Fork a process that runs $script with $fds as its descriptors 3 on.
     *
     * @param list<resource> $fds
     */
    private function fork(string $script, array $fds): int
    {
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('fork() failed: ' . \pcntl_strerror(\pcntl_get_last_error()));
        }
        if (0 === $pid) {
            // Its descriptors and stdio as ones that outlive this request; then this script
            // ends, and run_next() starts $script
            foreach ($fds as $n => $fd) {
                ext\dup2($fd, 3 + $n);
            }
            ext\dup2(\fopen('/dev/null', 'r'), 0);
            ext\dup2($this->out, 1);
            ext\dup2($this->out, 2);
            \chdir($this->workDir);
            ext\run_next($script);
            exit(0);
        }
        $this->children[$pid] = true;

        return $pid;
    }

    /** Wait for child $pid to exit: how it did. */
    private function waitFor(int $pid): string
    {
        while (0 === \pcntl_waitpid($pid, $status, \WNOHANG)) {
            phasync::sleep(0.01);
        }
        unset($this->children[$pid]);
        SharedLog::forget($pid);
        phasync::raiseFlag($this->exited);

        return \pcntl_wifexited($status) ? 'exit ' . \pcntl_wexitstatus($status) : 'signal ' . \pcntl_wtermsig($status);
    }

    /** @param resource $control */
    private function tell($control, string $letter): void
    {
        try {
            LotClient::send(\socket_import_stream($control), $letter);
        } catch (\RuntimeException) {
            // gone already
        }
    }
}
