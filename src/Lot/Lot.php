<?php

namespace Swerve\Lot;

use phasync;
use phasync\CancelledException;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Log\LoggerInterface;

/**
 * The master's side of the connections: accepts every client, waits for a request to arrive
 * on it (phasync::readable(); nothing is read), and hands it to the worker that asked first.
 * A worker hands a kept-alive connection back once its next request arrived, straight into
 * the line, or idle when the worker's lifecycle ended first, to wait here again.
 *
 * A worker told to stop ({@see Lot::stop()}) gets its stop letter for its next ask: 'S' (the
 * server shuts down) or 'L' (a reload replaces it); at its next lifecycle's hello, 's': exit. On
 * {@see Lot::shutdown()} the listener closes, connections without a request are closed, and
 * every worker's ask is answered with 'S' once no request waits in line.
 *
 * A process holds at most {@see Lot::connectionLimit()} connections. At the limit the master
 * closes the oldest connection still waiting for its request before it accepts another, and
 * accepts none while every one it holds has a request in line.
 *
 * Messages with the workers are packets on a SEQPACKET socket pair, one letter each, a
 * connection riding along as SCM_RIGHTS: see {@see LotClient}.
 *
 * @internal
 */
final class Lot
{
    /** Seconds a new connection may wait before its first request byte. */
    private const NEW_TIMEOUT = 10.0;

    /** Seconds a kept-alive connection may wait for its next request (as in Node). */
    private const KEPT_ALIVE_TIMEOUT = 5.0;

    /**
     * File descriptors a process keeps below its connection limit for everything else: the
     * listener, the workers' channels and logs, and in a worker the application's own files and
     * connections.
     */
    private const RESERVED_FDS = 64;

    /** At most once a minute: under a flood every accept would log it. */
    private const SHORT_LOG_EVERY = 60.0;

    /** See connectionLimit(). */
    private readonly int $max;

    /** Connections closed to make room since the last warning of it. */
    private int $reclaimed = 0;

    /** When the last warning that the limit was reached was logged. */
    private float $shortLogged = 0;

    /** Raised when a connection leaves the master: handed on, or closed. */
    private \stdClass $room;

    /** @var \SplQueue<resource> connections with a request waiting, oldest first */
    private \SplQueue $ready;

    /** @var \SplQueue<\Socket> workers waiting for a connection, oldest first */
    private \SplQueue $asking;

    /** @var \WeakMap<\Socket, string> workers told to stop, with their stop letter */
    private \WeakMap $stopping;

    /** @var \WeakMap<\Socket, \Closure(): void> called at a worker's first ask, see onReady() */
    private \WeakMap $onReady;

    /** @var array<int, \Socket> each worker's socket, by the resource id of the channel serve() was given */
    private array $sockets = [];

    /** @var array<int, \Fiber> wait()s for a connection's request, by resource id */
    private array $waiting = [];

    /** shutdown() was called. */
    private bool $closed = false;

    /** The coroutine of run(). */
    private ?\Fiber $accepting = null;

    /** @param resource $listener a listening stream socket */
    public function __construct(private $listener, private readonly LoggerInterface $logger)
    {
        $this->ready    = new \SplQueue();
        $this->asking   = new \SplQueue();
        $this->stopping = new \WeakMap();
        $this->onReady  = new \WeakMap();
        $this->room     = new \stdClass();
        $this->max      = self::connectionLimit();
        \stream_set_blocking($listener, false);
        // A response leaves in more than one write: without TCP_NODELAY, Nagle holds the last
        // one until the client's delayed ACK (40 ms) on every kept-alive request. Linux gives
        // the listener's to every connection it accepts.
        if ('unix_socket' !== \stream_get_meta_data($listener)['stream_type'] && !\socket_set_option(\socket_import_stream($listener), \SOL_TCP, \TCP_NODELAY, 1)) {
            throw new \RuntimeException('Setting TCP_NODELAY on the listener failed');
        }
    }

    /** Accept connections until shutdown(), which closes the listener. */
    public function run(): void
    {
        $this->accepting = \Fiber::getCurrent();
        while (true) {
            try {
                phasync::readable($this->listener);
                // Every connection waiting, not one per wakeup
                while ($this->makeRoom() && false !== $conn = @\stream_socket_accept($this->listener, 0)) {
                    \stream_set_blocking($conn, false);
                    phasync::go(fn () => $this->wait($conn, self::NEW_TIMEOUT));
                }
            } catch (CancelledException) {
                \fclose($this->listener);

                return;
            }
        }
    }

    /**
     * The open-file limit less RESERVED_FDS: the connections a process (the master, or a worker)
     * holds at most. Past the open-file limit it could accept or receive none, and nothing would
     * make room.
     */
    public static function connectionLimit(): int
    {
        $limit = \posix_getrlimit()['soft openfiles'];

        return 'unlimited' === $limit ? \PHP_INT_MAX : (int) $limit - self::RESERVED_FDS;
    }

    /**
     * Below the limit: true at once. At it, close the oldest connection waiting for its request
     * (wait() closes it) and return true; when every connection held has its request in line,
     * wait until one is handed on and return false: the listener is looked at again.
     */
    private function makeRoom(): bool
    {
        if (\count($this->waiting) + $this->ready->count() < $this->max) {
            return true;
        }
        $id = \array_key_first($this->waiting);
        if (null !== $id) {
            phasync::cancel($this->waiting[$id]);
            unset($this->waiting[$id]); // closed once its coroutine runs; it holds no slot from now
            ++$this->reclaimed;
        }
        $this->warnShort('At the limit of {max} connections in the master; {n} waiting for their request closed to make room since the last warning; raise the open-file limit (ulimit -n)');
        if (null === $id) {
            phasync::awaitFlag($this->room);

            return false;
        }

        return true;
    }

    /** Log that the limit was reached, at most once every SHORT_LOG_EVERY seconds. */
    private function warnShort(string $message): void
    {
        $now = \microtime(true);
        if ($now - $this->shortLogged >= self::SHORT_LOG_EVERY) {
            $this->shortLogged = $now;
            $this->logger->warning($message, ['max' => $this->max, 'n' => $this->reclaimed]);
            $this->reclaimed = 0;
        }
    }

    /**
     * Stop accepting: the listener closes, connections waiting for their request are closed,
     * and the workers are told to stop once no request waits in line.
     */
    public function shutdown(): void
    {
        $this->closed = true;
        phasync::cancel($this->accepting);
        foreach ($this->waiting as $fiber) {
            phasync::cancel($fiber);
        }
        $this->answerAsking();
    }

    /**
     * Tell the worker on $channel to stop: 'S' for a shutdown, 'L' for a reload. It gets that for
     * its next ask, or now while it asks.
     *
     * @param resource $channel the master's end of its pair, as serve() was given
     */
    public function stop($channel, string $letter): void
    {
        if (null === $socket = $this->sockets[(int) $channel] ?? null) {
            return; // gone already
        }
        $this->stopping[$socket] = $letter;
        $this->answerAsking();
    }

    /**
     * Serve one worker's channel (the master's end of its pair) until the worker exits.
     * $onReady is called when its application first asks for a connection: it has started.
     *
     * @param resource $channel
     */
    public function serve($channel, ?\Closure $onReady = null): void
    {
        $socket                           = \socket_import_stream($channel);
        $this->sockets[(int) $channel]    = $socket;
        if (null !== $onReady) {
            $this->onReady[$socket] = $onReady;
        }
        try {
            while (null !== $msg = LotClient::receive($channel, $socket)) {
                [$type, $conn] = $msg;
                match ($type) {
                    'N'     => $this->asked($socket),
                    'R'     => $this->ready(\socket_export_stream($conn)),
                    'B'     => phasync::go(fn () => $this->wait(\socket_export_stream($conn), self::KEPT_ALIVE_TIMEOUT)),
                    'H'     => $this->hello($socket),
                    default => throw new \UnexpectedValueException("A worker sent '$type'"),
                };
            }
        } finally {
            unset($this->sockets[(int) $channel]);
            $this->withdraw($socket);
        }
    }

    /**
     * Wait for a request to arrive on $conn, then hand it on. Closed by the client, or idle too
     * long: closed here.
     *
     * @param resource $conn
     */
    private function wait($conn, float $timeout): void
    {
        if ($this->closed) {
            \fclose($conn);

            return;
        }
        $this->waiting[(int) $conn] = \Fiber::getCurrent();
        try {
            phasync::readable($conn, $timeout);
        } catch (IOException|TimeoutException|CancelledException) {
            \fclose($conn);

            return;
        } finally {
            unset($this->waiting[(int) $conn]);
            phasync::raiseFlag($this->room);
        }
        if ('' === \stream_socket_recvfrom($conn, 1, \STREAM_PEEK)) {
            \fclose($conn); // readable because the client closed it

            return;
        }
        $this->ready($conn);
    }

    /**
     * $conn has a request waiting: to the worker that asked first, or in line.
     *
     * @param resource $conn
     */
    private function ready($conn): void
    {
        if ($this->asking->isEmpty()) {
            $this->ready->enqueue($conn);
        } else {
            $this->give($this->asking->dequeue(), $conn);
        }
    }

    private function asked(\Socket $worker): void
    {
        if (null !== $onReady = $this->onReady[$worker] ?? null) {
            unset($this->onReady[$worker]);
            $onReady();
        }
        if (isset($this->stopping[$worker])) {
            LotClient::send($worker, $this->stopping[$worker]);
        } elseif (!$this->ready->isEmpty()) {
            $this->give($worker, $this->ready->dequeue());
        } elseif ($this->closed) {
            LotClient::send($worker, 'S');
        } else {
            $this->asking->enqueue($worker);
        }
    }

    /** Answer the workers that ask and are to stop, or all of them once shut down. */
    private function answerAsking(): void
    {
        $left = new \SplQueue();
        foreach ($this->asking as $worker) {
            $letter = $this->stopping[$worker] ?? ($this->closed ? 'S' : null);
            if (null === $letter) {
                $left->enqueue($worker);
            } else {
                try {
                    LotClient::send($worker, $letter);
                } catch (\RuntimeException) {
                    // gone already
                }
            }
        }
        $this->asking = $left;
    }

    /**
     * A worker's new lifecycle: an ask of the one before it is void. Answered with 'h' after
     * whatever was sent before, so the new lifecycle takes those connections as its own.
     */
    private function hello(\Socket $worker): void
    {
        $this->withdraw($worker);
        LotClient::send($worker, isset($this->stopping[$worker]) || $this->closed ? 's' : 'h');
    }

    private function withdraw(\Socket $worker): void
    {
        $left = new \SplQueue();
        foreach ($this->asking as $w) {
            if ($w !== $worker) {
                $left->enqueue($w);
            }
        }
        $this->asking = $left;
    }

    /** @param resource $conn */
    private function give(\Socket $worker, $conn): void
    {
        try {
            LotClient::send($worker, 'C', $conn);
        } catch (\RuntimeException $e) {
            // The worker is gone: the connection waits for the next one
            $this->logger->warning('Handing a connection to a worker failed: {error}', ['error' => $e->getMessage()]);
            $this->withdraw($worker);
            $this->asking->isEmpty() ? $this->ready->enqueue($conn) : $this->give($this->asking->dequeue(), $conn);

            return;
        }
        \fclose($conn);
        phasync::raiseFlag($this->room);
    }
}
