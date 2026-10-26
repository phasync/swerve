<?php

namespace Swerve\Lot;

use phasync;
use phasync\IOException;
use phasync\TimeoutException;
use Psr\Log\LoggerInterface;

/**
 * The master's side of the connections: accepts every client, waits for a request to arrive
 * on it (phasync::readable(); nothing is read), and hands it to the worker that asked first.
 * A worker hands a kept-alive connection back once its next request arrived, straight into
 * the line, or idle when the worker's lifecycle ended first, to wait here again.
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

    /** @var \SplQueue<resource> connections with a request waiting, oldest first */
    private \SplQueue $ready;

    /** @var \SplQueue<\Socket> workers waiting for a connection, oldest first */
    private \SplQueue $asking;

    /** @param resource $listener a listening stream socket */
    public function __construct(private $listener, private readonly LoggerInterface $logger)
    {
        $this->ready  = new \SplQueue();
        $this->asking = new \SplQueue();
        \stream_set_blocking($listener, false);
        // A response leaves in more than one write: without TCP_NODELAY, Nagle holds the last
        // one until the client's delayed ACK (40 ms) on every kept-alive request. Linux gives
        // the listener's to every connection it accepts.
        if ('unix_socket' !== \stream_get_meta_data($listener)['stream_type'] && !\socket_set_option(\socket_import_stream($listener), \SOL_TCP, \TCP_NODELAY, 1)) {
            throw new \RuntimeException('Setting TCP_NODELAY on the listener failed');
        }
    }

    /** Accept connections until the listener closes. */
    public function run(): void
    {
        while (true) {
            try {
                phasync::readable($this->listener);
            } catch (IOException) {
                return;
            }
            $conn = @\stream_socket_accept($this->listener, 0);
            if (false !== $conn) {
                \stream_set_blocking($conn, false);
                phasync::go(fn () => $this->wait($conn, self::NEW_TIMEOUT));
            }
        }
    }

    /**
     * Serve one worker's channel (the master's end of its pair) until the worker exits.
     *
     * @param resource $channel
     */
    public function serve($channel): void
    {
        $socket = \socket_import_stream($channel);
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
        try {
            phasync::readable($conn, $timeout);
        } catch (IOException|TimeoutException) {
            \fclose($conn);

            return;
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
        if ($this->ready->isEmpty()) {
            $this->asking->enqueue($worker);
        } else {
            $this->give($worker, $this->ready->dequeue());
        }
    }

    /**
     * A worker's new lifecycle: an ask of the one before it is void. Answered with 'h' after
     * whatever was sent before, so the new lifecycle takes those connections as its own.
     */
    private function hello(\Socket $worker): void
    {
        $this->withdraw($worker);
        LotClient::send($worker, 'h');
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
    }
}
