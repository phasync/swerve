<?php

namespace Swerve\FastCGI;

use phasync\Server\Server;
use phasync\TimeoutException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Swerve\ServerInterface;
use Swerve\Swerve;

class FastCGIServer implements ServerInterface, LoggerAwareInterface
{
    private ?Swerve $swerve = null;
    private string $address;
    private ?Server $server = null;
    private ?\Fiber $coroutine = null;
    private LoggerInterface $logger;
    /**
     * @var array<int,\Fiber>
     */
    private array $fibers = [];
    private int $nextFiberId = 0;

    public function __construct(string $address, LoggerInterface $logger)
    {
        $this->address = $address;
        $this->logger = $logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'fcgi';
    }

    public function attach(Swerve $swerve): void
    {
        if ($this->swerve !== null) {
            throw new \RuntimeException('Already attached');
        }
        $this->swerve = $swerve;
    }

    public function detach(): void
    {
        if ($this->server !== null) {
            $this->close();
        }
        $this->swerve = null;
        $this->address = null;
    }

    public function open(\Closure $addConnectionFunction): void
    {
        if ($this->server !== null) {
            throw new \RuntimeException('Already open');
        }
        if ($this->swerve === null) {
            throw new \RuntimeException('Not attached');
        }
        $this->server = new Server($this->address);
        $this->coroutine = \phasync::go($this->run(...), [$addConnectionFunction]);
        $this->logger->info('Opened TCP socket at {address}', ['address' => $this->address]);
    }

    public function close(): void
    {
        if ($this->coroutine === null) {
            throw new \RuntimeException('Not opened');
        }
        \phasync::cancel($this->coroutine);
    }

    private function run(\Closure $addConnection): void
    {
        try {
            while (!$this->server->isClosed()) {
                while (true) {
                    try {
                        $socket = $this->server->accept($peerName);
                    } catch (TimeoutException) {
                        break;
                    }
                    if (!$socket) {
                        break;
                    }
                    // $this->logger->debug("Connect from {peerName}", ['peerName' => $peerName]);
                    $fcgiSocket = new FastCGISocket($addConnection, $socket, $peerName, $this->logger);
                    $fiberId = $this->nextFiberId++;
                    $this->fibers[$fiberId] = \phasync::go(function () use ($fiberId, $fcgiSocket) {
                        try {
                            $fcgiSocket->run();
                        } catch (\Throwable $e) {
                            $this->logger->notice(\get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString());
                        } finally {
                            unset($this->fibers[$fiberId]);
                        }
                    });
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('{exception}', ['exception' => $e]);
        } finally {
            foreach ($this->fibers as $key => $fiber) {
                if (!$fiber->isTerminated()) {
                    // $this->logger->debug("Cancelling connection");
                    \phasync::cancel($fiber, $e);
                    unset($this->fibers[$key]);
                }
            }
            if ($this->server !== null) {
                $this->server->close();
                $this->server = null;
                $this->logger->info('Closed TCP socket at {address}', ['address' => $this->address]);
            }
            $this->coroutine = null;
        }
    }
}
