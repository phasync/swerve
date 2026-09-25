<?php
namespace Swerve\Util;

use Exception;
use LogicException;
use Psr\Log\LoggerInterface;

class Cluster {
    private $numWorkers;
    private $masterPid;
    /**
     * Worker slot (0 to numWorkers-1) per worker PID. A restarted worker takes over the
     * slot of the one it replaces, so per-slot resources such as socket paths stay the same.
     *
     * @var array<int, int>
     */
    private $workerPids = [];

    /**
     * In a worker process: its slot.
     */
    private ?int $slot = null;
    private $stop = false;
    private LoggerInterface $logger;

    public function __construct(int $numWorkers, LoggerInterface $logger) {
        if (!function_exists('pcntl_fork')) {
            throw new Exception('PCNTL functions not available. Please ensure the PCNTL extension is installed and enabled.');
        }

        $this->logger = $logger;

        $this->numWorkers = $numWorkers;
        $this->masterPid = posix_getpid();
        $this->logger->debug('Master process PID is {pid}', ['pid' => $this->numWorkers]);
    }

    public function launch() {
        $this->assertIsMaster();
        $this->logger->debug('Launching {numWorkers} worker processes', ['numWorkers' => $this->numWorkers]);;
        $this->stop = false; // Reset stopping flag on launch
        for ($i = 0; $i < $this->numWorkers; $i++) {
            $pid = $this->forkWorker($i);
            if ($pid === 0) {
                $this->logger->debug('Worker process {pid} started', ['pid' => posix_getpid()]);
                // This is a worker
                return false;
            }
        }
        return $this->isMaster();
    }

    private function forkWorker(int $slot) {
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("Could not fork worker");
        } elseif ($pid) {
            // This is the master process
            $this->workerPids[$pid] = $slot;
            return $pid;
        } else {
            // This is a worker process
            $this->slot = $slot;
            return 0;
        }
    }

    /**
     * The slot of this worker process, from 0 to the number of workers minus one; null in
     * the master process.
     */
    public function getSlot(): ?int {
        return $this->slot;
    }

    public function getWorkerCount(): int {
        return $this->numWorkers;
    }

    public function isWorker() {
        return posix_getpid() !== $this->masterPid;
    }

    private function isMaster() {
        return posix_getpid() === $this->masterPid;
    }

    public function getWorkerPids(): array {
        return \array_keys($this->workerPids);
    }

    public function relaunchChildren(): bool {
        $this->assertIsMaster();
        if (!$this->stop) {
            foreach ($this->workerPids as $pid => $slot) {
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res == -1 || $res > 0) {
                    $this->logger->notice("Worker {pid} exited", ['pid' => $pid]);
                    unset($this->workerPids[$pid]);
                    if (!$this->stop) { // Only restart if not stopping
                        if ($this->forkWorker($slot) === 0) {
                            // A child was launched
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function stop() {
        $this->assertIsMaster();
        $this->stop = true; // Set flag to stop restarting workers
        foreach ($this->workerPids as $pid => $slot) {
            posix_kill($pid, SIGTERM); // Send termination signal
            pcntl_waitpid($pid, $status); // Wait for the process to exit
            $this->logger->info("Stopped worker {pid}", ['pid' => $pid]);
            unset($this->workerPids[$pid]);
        }
    }

    private function assertIsMaster(): void {
        if (!$this->isMaster()) {
            throw new LogicException("Can't manage processes via the masters' cluster instance.");
        }
    }
}