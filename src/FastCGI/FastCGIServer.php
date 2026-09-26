<?php

namespace Swerve\FastCGI;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Swerve\ServerInterface;
use Swerve\Swerve;
use Swerve\Util\System;

class FastCGIServer implements ServerInterface, LoggerAwareInterface
{
    private ?Swerve $swerve = null;
    private string $address;
    /** @var resource|null */
    private $listener = null;
    private ?\Fiber $coroutine = null;
    private ?\Closure $addConnection = null;
    private LoggerInterface $logger;
    /**
     * @var array<int,\Fiber>
     */
    private array $fibers = [];
    /**
     * The sockets of $fibers, by the same keys.
     *
     * @var array<int,FastCGISocket>
     */
    private array $sockets = [];
    private int $nextFiberId = 0;
    private bool $draining = false;

    /**
     * PHP's shutdown began, after an exit() (in a request, or the worker's at its drain
     * deadline) or a fatal error: the suspended coroutines are destroyed, which runs their
     * finally blocks, and the event loop can no longer take a cancel or a raised flag; trying
     * turns the exit into a PHP fatal error (exit 255). Set by a shutdown function, which PHP
     * calls before it destroys them.
     */
    public static bool $exiting = false;

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
        if ($this->listener !== null) {
            $this->close();
        }
        $this->swerve = null;
        $this->address = null;
    }

    public function open(\Closure $addConnectionFunction): void
    {
        if ($this->listener !== null) {
            throw new \RuntimeException('Already open');
        }
        if ($this->swerve === null) {
            throw new \RuntimeException('Not attached');
        }
        $this->listener      = System::listen($this->address);
        $this->addConnection = $addConnectionFunction;
        $this->coroutine     = \phasync::go($this->run(...));
        \register_shutdown_function(static function () { self::$exiting = true; });
        $this->logger->info('Opened TCP socket at {address}', ['address' => $this->address]);
    }

    public function close(): void
    {
        if ($this->coroutine === null) {
            throw new \RuntimeException('Not opened');
        }
        if (!self::$exiting) {
            \phasync::cancel($this->coroutine);
        }
    }

    /**
     * The connections already waiting in the kernel's accept queue are accepted and served
     * first. Shutting the listener down, not only closing it, then takes it out of the
     * SO_REUSEPORT group also where a process the application started still holds it, and
     * wakes run(). A connection whose handshake completes in between is reset; a front server
     * retries it on another worker, as nginx does with `fastcgi_next_upstream error`.
     */
    public function drain(): void
    {
        while ($socket = @\stream_socket_accept($this->listener, 0, $peerName)) {
            $this->adopt($socket, $peerName);
        }
        $this->draining = true;
        \stream_socket_shutdown($this->listener, \STREAM_SHUT_RD);
        foreach ($this->sockets as $socket) {
            $socket->drain();
        }
        $this->logger->info('Draining FastCGI at {address}: {n} sockets open', ['address' => $this->address, 'n' => \count($this->sockets)]);
    }

    /**
     * Accept connections until drained or closed. Like NativeHttpServer::run(), a connection
     * that is waiting, yet can't be accepted (out of descriptors), is retried after a pause,
     * not at once, forever.
     */
    private function run(): void
    {
        try {
            $ready = false;
            while (true) {
                $socket = @\stream_socket_accept($this->listener, 0, $peerName);
                if (false === $socket) {
                    if ($ready) {
                        \phasync::sleep(0.1);
                    }
                    \phasync::readable($this->listener, \PHP_FLOAT_MAX);
                    if ($this->draining) {
                        break;
                    }
                    $ready = true;
                    continue;
                }
                $ready = false;
                $this->adopt($socket, $peerName);
            }
        } catch (\Throwable $e) {
            if (!$this->draining) {
                $this->logger->error('{exception}', ['exception' => $e]);
            }
        } finally {
            // Draining lets the sockets finish; close() is the hard stop
            if (!$this->draining && !self::$exiting) {
                foreach ($this->fibers as $key => $fiber) {
                    if (!$fiber->isTerminated()) {
                        \phasync::cancel($fiber);
                        unset($this->fibers[$key], $this->sockets[$key]);
                    }
                }
            }
            if ($this->listener !== null) {
                \fclose($this->listener);
                $this->listener = null;
                $this->logger->info('Closed TCP socket at {address}', ['address' => $this->address]);
            }
            $this->coroutine = null;
        }
    }

    /**
     * @param resource $socket
     */
    private function adopt($socket, string $peerName): void
    {
        $fcgiSocket              = new FastCGISocket($this->addConnection, $socket, $peerName, $this->logger);
        $fiberId                 = $this->nextFiberId++;
        $this->sockets[$fiberId] = $fcgiSocket;
        $this->fibers[$fiberId]  = \phasync::go(function () use ($fiberId, $fcgiSocket) {
            try {
                $fcgiSocket->run();
            } catch (\Throwable $e) {
                $this->logger->notice(\get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString());
            } finally {
                unset($this->fibers[$fiberId], $this->sockets[$fiberId]);
            }
        });
    }
}
