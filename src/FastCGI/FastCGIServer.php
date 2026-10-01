<?php

namespace Swerve\FastCGI;

use Psr\Log\LoggerInterface;
use Swerve\Dispatcher;
use Swerve\ServerInterface;
use Swerve\Util\System;

/**
 * FastCGI (--fastcgi), for a web server in front of swerve, such as nginx or HAProxy. Requests
 * are multiplexed on the connections it keeps open. A protocol upgrade is not
 * served: no front server tunnels a 101 over FastCGI, so it needs HTTP mode.
 */
final class FastCGIServer implements ServerInterface
{
    private bool $shared = false;
    private ?\Fiber $waiting = null;
    private ?\RuntimeException $wake = null;
    /** @var resource|null */
    private $listener = null;
    /** run() has not returned, see adopt(). */
    private bool $running = false;
    /**
     * @var array<int,FastCGISocket>
     */
    private array $sockets = [];
    private bool $draining = false;

    /**
     * PHP's shutdown began, after an exit() (in a request, or the worker's at its drain
     * deadline) or a fatal error: the suspended coroutines are destroyed, which runs their
     * finally blocks, and the event loop can no longer take a cancel or a raised flag; trying
     * turns the exit into a PHP fatal error (exit 255). Set by a shutdown function, which PHP
     * calls before it destroys them.
     */
    public static bool $exiting = false;

    public function __construct(
        private readonly string $address,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function listen(): void
    {
        $this->listener = System::listen($this->address);
        $this->shared   = \str_starts_with($this->address, 'unix:');
        \register_shutdown_function(static function () { self::$exiting = true; });
        $this->logger->info('Serving FastCGI at {address}', ['address' => $this->address]);
    }

    /**
     * Accept connections until drained, and return once the last one ended. Like
     * HttpServer::run(), a connection that is waiting, yet can't be accepted (out of
     * descriptors), is retried after a pause, not at once, forever.
     */
    public function run(): void
    {
        $this->running = true;
        try {
            $ready = false;
            while (true) {
                $socket = @\stream_socket_accept($this->listener, 0, $peerName);
                if (false === $socket) {
                    \error_clear_last(); // an empty queue, or out of descriptors: retried either way
                    if ($ready && $this->shared) {
                        // Another worker may have accepted it first: a failure only if one is still waiting
                        $r     = [$this->listener];
                        $w     = $x = null;
                        $ready = \stream_select($r, $w, $x, 0) > 0;
                    }
                    if ($ready) {
                        \phasync::sleep(0.1);
                    }
                    $this->waitForClient();
                    if ($this->draining) {
                        break;
                    }
                    $ready = true;
                    continue;
                }
                $ready = false;
                $this->adopt($socket, $peerName);
            }
            // Closed here, not in drain(): this coroutine was waiting on it
            \fclose($this->listener);
            $this->listener = null;
            $this->logger->info('Closed FastCGI at {address}', ['address' => $this->address]);
            while ($this->sockets) {
                \phasync::awaitFlag($this);
            }
        } finally {
            // Also when the worker exits at its drain deadline, destroying this coroutine before
            // those of the sockets still open: they must not wake it then
            $this->running = false;
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
        if ($this->shared) {
            $this->draining = true;
            if ($this->waiting) {
                \phasync::cancel($this->waiting, $this->wake = new \RuntimeException('drain'));
            }
        } else {
            while ($socket = @\stream_socket_accept($this->listener, 0, $peerName)) {
                $this->adopt($socket, $peerName);
            }
            \error_clear_last(); // the queue is empty: not an error for the application to see
            $this->draining = true;
            \stream_socket_shutdown($this->listener, \STREAM_SHUT_RD);
        }
        foreach ($this->sockets as $socket) {
            $socket->drain();
        }
        $this->logger->info('Draining FastCGI at {address}: {n} sockets open', ['address' => $this->address, 'n' => \count($this->sockets)]);
    }

    /**
     * Wait for a client. The listener of a unix: address is shared with the other workers, so
     * drain() can't shut it down to wake this: its wait coroutine is cancelled instead.
     */
    private function waitForClient(): void
    {
        if (!$this->shared) {
            \phasync::readable($this->listener, \PHP_FLOAT_MAX);

            return;
        }
        // A cancellation stays on a coroutine until it ends, and run() waits again after the
        // loop: the wait is a coroutine of its own, which is the one drain() cancels
        $this->waiting = \phasync::go(fn () => \phasync::readable($this->listener, \PHP_FLOAT_MAX));
        try {
            \phasync::await($this->waiting);
        } catch (\RuntimeException $e) {
            if ($e !== $this->wake) {
                throw $e;
            }
        } finally {
            $this->waiting = null;
        }
    }

    /**
     * @param resource $socket
     */
    private function adopt($socket, string $peerName): void
    {
        $fcgiSocket = new FastCGISocket($this->dispatcher, $socket, $peerName, $this->logger);
        $id         = \spl_object_id($fcgiSocket);

        $this->sockets[$id] = $fcgiSocket;
        \phasync::go(function () use ($id, $fcgiSocket) {
            try {
                $fcgiSocket->run();
            } catch (\Throwable $e) {
                $this->logger->notice(\get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            } finally {
                unset($this->sockets[$id]);
                if ($this->running && $this->draining) {
                    \phasync::raiseFlag($this); // run() waits for the last one
                }
            }
        });
    }
}
