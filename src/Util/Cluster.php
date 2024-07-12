<?php
namespace Swerve\Util;

use Exception;
use LogicException;
use Psr\Log\LoggerInterface;

class Cluster {
    private $numWorkers;
    private $masterPid;
    private $workerPids = [];
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
            $pid = $this->forkWorker();
            if ($pid === 0) {
                $this->logger->debug('Worker process {pid} started', ['pid' => posix_getpid()]);
                // This is a worker
                return false;
            }
        }
        return $this->isMaster();
    }

    private function forkWorker() {        
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new Exception("Could not fork worker");
        } elseif ($pid) {
            // This is the master process
            $this->workerPids[$pid] = true;
            return $pid;
        } else {
            return 0;
            // This is a worker process
        }
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
            foreach ($this->workerPids as $pid => $active) {
                if ($active) {
                    $res = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($res == -1 || $res > 0) {
                        $this->logger->notice("Worker {pid} exited", ['pid' => $pid]);
                        unset($this->workerPids[$pid]);
                        if (!$this->stop) { // Only restart if not stopping
                            if ($this->forkWorker() === 0) {
                                // A child was launched
                                return true;
                            }
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
        foreach ($this->workerPids as $pid => $active) {
            if ($active) {
                posix_kill($pid, SIGTERM); // Send termination signal
                pcntl_waitpid($pid, $status); // Wait for the process to exit
                $this->logger->info("Stopped worker {pid}", ['pid' => $pid]);
                unset($this->workerPids[$pid]);
            }
        }
    }

    private function assertIsMaster(): void {
        if (!$this->isMaster()) {
            throw new LogicException("Can't manage processes via the masters' cluster instance.");
        }
    }
}