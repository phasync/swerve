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

    private static ?self $instance = null;

    /** @var resource */
    private $channel;

    private \Socket $socket;

    /** @var list<resource> connections sent to the previous lifecycle, never taken */
    private array $stash = [];

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
        self::send($this->socket, 'H');
        while ('h' !== ([$type, $conn] = $this->receiveOrFail())[0]) {
            $this->stash[] = \socket_export_stream($conn);
        }
    }

    /**
     * A connection with a request waiting, as soon as the master has one.
     *
     * @return resource
     */
    public function next()
    {
        if ([] !== $this->stash) {
            return \array_shift($this->stash);
        }
        self::send($this->socket, 'N');
        [$type, $conn] = $this->receiveOrFail();
        if ('C' !== $type || null === $conn) {
            throw new \UnexpectedValueException("The master answered '$type' to a worker's ask");
        }

        return \socket_export_stream($conn);
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
