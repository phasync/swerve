<?php

namespace Swerve\Lot;

use phasync;

/**
 * A worker's side of the master's {@see Lot}, over the SEQPACKET socket the master passed as
 * file descriptor 3, which stays open across the worker's lifecycles (run_next()).
 *
 * Packets, one letter, a connection riding along as SCM_RIGHTS:
 *
 * - worker 'H': a lifecycle starts. The master forgets the ask of the one before and answers
 *   'h', after any connection it sent to that one: those are this lifecycle's to serve.
 * - worker 'N': next, please. The master answers 'C' with a connection that has a request
 *   waiting, as soon as it has one.
 * - worker 'B' + connection: back, kept alive and idle after a response.
 *
 * @internal
 */
final class LotClient
{
    private const FD = 3;

    /** Requests in a row this worker serves on an idle connection; then it goes back to the master. */
    private const KEEP_LIMIT = 20;

    private static ?self $instance = null;

    /** @var resource */
    private $channel;

    private \Socket $socket;

    /** @var list<resource> received, not yet served: sent to the previous lifecycle, or answering an ask after accept() reused an idle one */
    private array $stash = [];

    /** @var array<int, resource> being served, by resource id */
    private array $busy = [];

    /** @var list<array{0: resource, 1: int}> idle after a kept-alive response, and the requests served on each in a row */
    private array $idle = [];

    /** An 'N' is unanswered. */
    private bool $asking = false;

    /** The accept() waiting for the master, woken when a connection turns idle. */
    private ?\Fiber $waiter = null;

    /** This lifecycle's client: one per lifecycle, since run_next() resets the statics. */
    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $channel = @\fopen('php://fd/' . self::FD, 'r+');
        $socket  = false === $channel ? false : @\socket_import_stream($channel);
        if (false === $socket) {
            throw new \LogicException('Swerve::accept() serves requests in a worker that swerve started: run the application with `swerve`');
        }
        $this->channel = $channel;
        $this->socket  = $socket;
        \register_shutdown_function($this->shutdown(...));
        self::send($this->socket, 'H');
        while ('h' !== ([$type, $conn] = $this->receiveOrFail())[0]) {
            $this->stash[] = \socket_export_stream($conn);
        }
    }

    /**
     * The next connection with a request waiting, and the requests this worker served on it in
     * a row. An idle connection whose next request has arrived is reused; the other idle ones go
     * back to the master. Waits for the master asynchronously, and looks at the idle connections
     * again each time one turns idle meanwhile.
     *
     * @return array{0: resource, 1: int}
     */
    public function next(): array
    {
        while (true) {
            while ([] !== $this->idle) {
                [$conn, $served] = \array_shift($this->idle);
                if ($served < self::KEEP_LIMIT && self::readableNow($conn)) {
                    if ('' === \stream_socket_recvfrom($conn, 1, \STREAM_PEEK)) {
                        \fclose($conn); // readable because the client closed it
                        continue;
                    }

                    return $this->serving($conn, $served);
                }
                $this->giveBack($conn);
            }
            if ([] !== $this->stash) {
                return $this->serving(\array_shift($this->stash), 0);
            }
            if (!$this->asking) {
                self::send($this->socket, 'N');
                $this->asking = true;
            }
            $this->waiter = phasync::getFiber();
            try {
                [$type, $conn] = $this->receiveOrFail();
            } catch (IdleWake) {
                continue;
            } finally {
                $this->waiter = null;
            }
            if ('C' !== $type || null === $conn) {
                throw new \UnexpectedValueException("The master answered '$type' to a worker's ask");
            }
            $this->asking = false;

            return $this->serving(\socket_export_stream($conn), 0);
        }
    }

    /**
     * A response was sent and the connection is kept alive: idle, for the next accept().
     *
     * @param resource $conn
     * @param int      $served requests this worker served on it in a row
     */
    public function idle($conn, int $served): void
    {
        unset($this->busy[(int) $conn]);
        $this->idle[] = [$conn, $served];
        if (null !== $this->waiter) {
            try {
                phasync::throw($this->waiter, new IdleWake());
            } catch (\LogicException) {
                // woken already
            }
        }
    }

    /**
     * The connection was closed while served (Connection: close, an error).
     *
     * @param resource $conn
     */
    public function closed($conn): void
    {
        unset($this->busy[(int) $conn]);
    }

    /** @param resource $conn */
    private function serving($conn, int $served): array
    {
        $this->busy[(int) $conn] = $conn;

        return [$conn, $served];
    }

    /**
     * The lifecycle ends: idle and unserved connections go back to the master; one still being
     * served (the request was cut short, by die() say) is closed.
     */
    private function shutdown(): void
    {
        foreach ($this->idle as [$conn]) {
            $this->giveBack($conn);
        }
        foreach ($this->stash as $conn) {
            $this->giveBack($conn);
        }
        foreach ($this->busy as $conn) {
            \fclose($conn);
        }
        $this->idle = $this->stash = $this->busy = [];
    }

    /** @param resource $conn */
    private static function readableNow($conn): bool
    {
        $read = [$conn];
        $none = null;

        return 1 === \stream_select($read, $none, $none, 0);
    }

    /**
     * Hand a kept-alive connection back to the master, which waits for its next request.
     *
     * @param resource $conn
     */
    public function giveBack($conn): void
    {
        self::send($this->socket, 'B', $conn);
        \fclose($conn);
    }

    /** @return array{0: string, 1: ?\Socket} */
    private function receiveOrFail(): array
    {
        return self::receive($this->channel, $this->socket) ?? throw new \RuntimeException('The swerve master is gone');
    }

    /** @param resource|\Socket|null $conn */
    public static function send(\Socket $to, string $type, $conn = null): void
    {
        $message = ['iov' => [$type]];
        if (null !== $conn) {
            $message['control'] = [['level' => \SOL_SOCKET, 'type' => \SCM_RIGHTS, 'data' => [$conn]]];
        }
        // MSG_NOSIGNAL: a peer that just died is an error here, not a SIGPIPE killing this process
        if (false === @\socket_sendmsg($to, $message, \MSG_NOSIGNAL)) {
            throw new \RuntimeException('Sending to the swerve master or worker failed: ' . \socket_strerror(\socket_last_error($to)));
        }
    }

    /**
     * The next packet: [letter, the connection with it or null]; null once the other side is gone.
     *
     * @param resource $channel the stream $socket was imported from, to wait on
     *
     * @return array{0: string, 1: ?\Socket}|null
     */
    public static function receive($channel, \Socket $socket): ?array
    {
        while (true) {
            $message = ['buffer_size' => 1, 'controllen' => \socket_cmsg_space(\SOL_SOCKET, \SCM_RIGHTS, 1)];
            $n       = @\socket_recvmsg($socket, $message, \MSG_DONTWAIT);
            if (0 === $n) {
                return null;
            }
            if (false !== $n) {
                return [$message['iov'][0], $message['control'][0]['data'][0] ?? null];
            }
            // socket_recvmsg() records its error globally, not on the socket
            $error = \socket_last_error();
            \socket_clear_error();
            if (\SOCKET_ECONNRESET === $error) {
                return null; // the other side died with a packet of ours unread
            }
            if (\SOCKET_EAGAIN !== $error && \SOCKET_EWOULDBLOCK !== $error) {
                throw new \RuntimeException('Receiving from the swerve master or worker failed: ' . \socket_strerror($error));
            }
            phasync::readable($channel);
        }
    }
}

/**
 * Wakes the accept() waiting for the master: a connection turned idle.
 *
 * @internal
 */
final class IdleWake extends \Exception
{
}
