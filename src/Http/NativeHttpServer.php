<?php

namespace Swerve\Http;

use phasync;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP mode (--http): a worker serving HTTP/1.1 itself, with no proxy in front. Every worker
 * listens on the same address (SO_REUSEPORT), and the kernel spreads new connections
 * over them.
 *
 * Request and response bodies stream by default. $bufferResponses reads each response body
 * whole first, up to 8 MiB (a larger one streams after all), and sends it with a
 * Content-Length in one write. That delays Server-Sent Events and long-polling responses until
 * they end, and memory use grows with response size.
 *
 * A worker serves at most so many connections at once that its file descriptors stay below
 * the limit (see run()); more wait in the kernel's accept queue. At that limit, connections
 * waiting on their client are closed to make room, see reclaim().
 */
final class NativeHttpServer
{
    /**
     * File descriptors left for the listener, logs and the application's own files and
     * connections, below the connection limit.
     */
    private const RESERVED_FDS = 64;

    /** The native stream_select() fails outright for a file descriptor at or above this. */
    private const FD_SETSIZE = 1024;

    /**
     * Connections being served, oldest first.
     *
     * @var array<int, NativeHttpConnection>
     */
    private array $connections = [];

    /**
     * @param int $maxBodySize the largest request body (413); PHP_INT_MAX for no limit
     */
    public function __construct(
        private readonly string $address,
        private readonly RequestHandlerInterface $handler,
        private readonly LoggerInterface $logger,
        private readonly bool $bufferResponses = false,
        private readonly int $maxBodySize = NativeHttpConnection::MAX_BODY,
    ) {
    }

    /**
     * Accept and serve connections until the process ends. Call from inside phasync::run().
     *
     * Without the phasync extension, the native stream_select() fails for every stream of the
     * worker at once when one of them has a descriptor number of FD_SETSIZE or more, which
     * would end this loop and every connection. Capping the connections can't prevent that,
     * since the application's own descriptors (a file being sent, a database connection) take
     * numbers too. So the worker's open-file limit is lowered to FD_SETSIZE: no descriptor
     * ever gets such a number, and running out fails only the one accept (retried when
     * descriptors are free again) or the one application call that opens something.
     *
     * Throws when the listener fails; the worker must then end, so that it is started again.
     */
    public function run(): void
    {
        $rlimit = \posix_getrlimit();
        $limit  = (int) $rlimit['soft openfiles'];
        if (!\function_exists('phasync\ext\stream_select') && $limit > self::FD_SETSIZE) {
            $hard = 'unlimited' === $rlimit['hard openfiles'] ? \POSIX_RLIM_INFINITY : (int) $rlimit['hard openfiles'];
            if (!\posix_setrlimit(\POSIX_RLIMIT_NOFILE, self::FD_SETSIZE, $hard)) {
                throw new \RuntimeException('Could not lower the open-file limit to ' . self::FD_SETSIZE);
            }
            $limit = self::FD_SETSIZE;
        }
        $max = $limit - self::RESERVED_FDS;

        // Not phasync\Net\listen(): its accept loop retries at once, forever, when accepting
        // fails for lack of descriptors
        $listener = @\stream_socket_server("tcp://{$this->address}", $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            \stream_context_create(['socket' => ['so_reuseport' => true, 'tcp_nodelay' => true, 'backlog' => 65535]]));
        if (false === $listener) {
            throw new \RuntimeException("Could not listen at {$this->address}: $errstr", $errno);
        }
        \stream_set_blocking($listener, false);
        $this->logger->info('Serving HTTP at {address}, at most {max} connections', ['address' => \stream_socket_get_name($listener, false), 'max' => $max]);

        $ready = false;
        while (true) {
            // A burst of connections is accepted without waiting on the event loop in between
            $socket = @\stream_socket_accept($listener, 0, $peer);
            if (false === $socket) {
                if ($ready) {
                    // A connection was waiting, yet accepting it failed: out of descriptors
                    phasync::sleep(0.1);
                }
                phasync::readable($listener, \PHP_FLOAT_MAX);
                $ready = true;
                continue;
            }
            $ready = false;
            \stream_set_blocking($socket, false);
            $connection = new NativeHttpConnection($socket, $peer, $this->handler, $this->logger, $this->bufferResponses, $this->maxBodySize);
            $id         = \spl_object_id($connection);

            $this->connections[$id] = $connection;
            phasync::go(function () use ($connection, $id, $max) {
                try {
                    $connection->serve();
                } finally {
                    if ($max === \count($this->connections)) {
                        phasync::raiseFlag($this); // the accept loop waits for a free place
                    }
                    unset($this->connections[$id]);
                }
            });
            // At the limit: once a client waits to be accepted, make room for it
            while (\count($this->connections) >= $max) {
                phasync::readable($listener, \PHP_FLOAT_MAX);
                if (\count($this->connections) >= $max) {
                    $this->reclaim();
                    phasync::awaitFlag($this);
                }
            }
        }
    }

    /**
     * At the limit, with a client waiting to be accepted: close a connection that waits on its
     * client, as nginx reuses its idle keep-alive connections when it runs short. Else a client
     * holding the worker's connections, which costs it almost nothing, would lock everyone else
     * out. The oldest first of: those already answered (lingering before the close, or skipping
     * an unread body), then those that never completed a request (a new connection that sent
     * nothing yet, a slow head), then the kept-alive ones, and last those waiting for more of a
     * request body.
     */
    private function reclaim(): void
    {
        foreach ([NativeHttpConnection::ANSWERED, NativeHttpConnection::NEW, NativeHttpConnection::KEPT_ALIVE, NativeHttpConnection::BODY] as $state) {
            foreach ($this->connections as $connection) {
                if ($connection->reclaim($state)) {
                    return;
                }
            }
        }
    }
}
