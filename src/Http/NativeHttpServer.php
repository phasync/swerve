<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\Net\Duplex;
use phasync\Net\Server;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP mode (--http): a worker serving HTTP/1.1 itself, with no proxy in front. Every worker
 * listens on the same address (SO_REUSEPORT), and the kernel spreads new connections
 * over them. Connections come from phasync/net's Server, as Duplex objects: with the phasync
 * extension's tcp_server(), one stream carries the listener and every connection.
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

    private ?Server $server = null;

    /** The most connections served at once, see listen(). */
    private int $max = 0;

    /** What serves more connections, for the log, see listen(). */
    private string $remedy = 'raise the open-file limit (ulimit -n) or add workers';

    private bool $draining = false;

    /** run() has not returned, see adopt(). */
    private bool $running = false;

    /** When running short (of connections, of descriptors) was last logged: once a minute at most. */
    private float $shortLogged = -\INF;

    /** Connections closed to make room since the last log of it, see reclaim(). */
    private int $reclaimed = 0;

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
     * Open the listener. Throws when that fails, before the worker tells the master it is ready.
     *
     * Without the phasync extension, the native stream_select() fails for every stream of the
     * worker at once when one of them has a descriptor number of FD_SETSIZE or more, which
     * would end the accept loop and every connection. Capping the connections can't prevent
     * that, since the application's own descriptors (a file being sent, a database connection)
     * take numbers too. So the worker's open-file limit is lowered to FD_SETSIZE: no descriptor
     * ever gets such a number, and running out fails only the one accept (retried when
     * descriptors are free again) or the one application call that opens something.
     */
    public function listen(): void
    {
        $rlimit = \posix_getrlimit();
        $limit  = (int) $rlimit['soft openfiles'];
        if (!\function_exists('phasync\ext\stream_select') && $limit > self::FD_SETSIZE) {
            $hard = 'unlimited' === $rlimit['hard openfiles'] ? \POSIX_RLIM_INFINITY : (int) $rlimit['hard openfiles'];
            if (!\posix_setrlimit(\POSIX_RLIMIT_NOFILE, self::FD_SETSIZE, $hard)) {
                throw new \RuntimeException('Could not lower the open-file limit to ' . self::FD_SETSIZE);
            }
            $limit        = self::FD_SETSIZE;
            $this->remedy = 'add workers, or install the phasync extension: without it a worker serves no more, whatever the open-file limit';
        }
        $this->max = $limit - self::RESERVED_FDS;
        \register_shutdown_function(static function () { NativeHttpConnection::$exiting = true; });

        // Close-on-exec, so that processes the application starts don't inherit the listener
        $this->server = new Server($this->address, ['backlog' => 65535, 'reuseport' => true, 'nodelay' => true, 'max_connections' => $this->max]);
        $this->logger->info('Serving HTTP at {address}, at most {max} connections{how}', ['address' => $this->server->addr(), 'max' => $this->max, 'how' => $this->server->isMultiplexed() ? ', multiplexed' : '']);
    }

    /**
     * Accept and serve connections until drained: returns once drain() was called and every
     * connection has ended. Call from inside phasync::run(), after listen().
     */
    public function run(): void
    {
        $this->running = true;
        // At the limit, with a client waiting: make room for it
        $reclaimer = phasync::go(function () {
            try {
                while (true) {
                    $this->server->awaitFull();
                    $this->reclaim();
                    $this->logShort('At the limit of {max} connections; {n} waiting on their clients closed to make room since the last warning; {remedy}', ['max' => $this->max, 'n' => $this->reclaimed, 'remedy' => $this->remedy]);
                    phasync::awaitFlag($this); // until a connection ends
                }
            } catch (IOException|phasync\CancelledException) {
                // The server stopped listening
            }
        });
        try {
            // Accepts until drain() closes the server, then hands out the connections that were
            // already waiting
            foreach ($this->server as $peer => $connection) {
                $this->adopt($connection, $peer);
            }
            if (!$reclaimer->isTerminated()) {
                phasync::cancel($reclaimer);
            }
            while ($this->connections) {
                phasync::awaitFlag($this);
            }
        } finally {
            // Also when the worker exits at its drain deadline, destroying this coroutine before
            // those of the connections still open: they must not wake it then
            $this->running = false;
        }
    }

    /**
     * Stop accepting, and let the connections finish: requests in flight are answered with
     * `Connection: close`, idle keep-alive connections are closed at once, new ones that sent
     * nothing yet soon after. An upgraded connection's request body reaches EOF, which tells
     * the application to end it. run() then returns once the last connection ended.
     *
     * The connections already waiting in the kernel's accept queue are accepted and served
     * first; shutting the listener down then removes it from the SO_REUSEPORT group at once,
     * so the other workers get every new connection, and wakes run(). With
     * net.ipv4.tcp_migrate_req=0, a handshake that completes in the microseconds between the
     * two is reset; with 1, the kernel moves it to another worker's listener.
     */
    public function drain(): void
    {
        $this->draining = true;
        // Stops listening; the connections already waiting are still handed to run()
        $this->server->close();
        $upgraded = 0;
        foreach ($this->connections as $connection) {
            $upgraded += (int) $connection->drain();
        }
        phasync::raiseFlag($this); // run() may wait for a free place
        $this->logger->info('Draining HTTP at {address}: {n} connections open, {upgraded} upgraded', ['address' => $this->address, 'n' => \count($this->connections), 'upgraded' => $upgraded]);
    }

    private function adopt(Duplex $conn, string $peer): void
    {
        $connection = new NativeHttpConnection($conn, $peer, $this->handler, $this->logger, $this->bufferResponses, $this->maxBodySize);
        $id         = \spl_object_id($connection);

        $this->connections[$id] = $connection;
        if ($this->draining) {
            $connection->drain(); // was waiting when the drain began
        }
        phasync::go(function () use ($connection, $id) {
            try {
                $connection->serve();
            } finally {
                unset($this->connections[$id]);
                if ($this->running) {
                    phasync::raiseFlag($this); // run() waits for the last one; the reclaimer for any
                }
            }
        });
    }

    /**
     * At the limit, with a client waiting to be accepted: close a connection that waits on its
     * client, as nginx reuses its idle keep-alive connections when it runs short. Else a client
     * holding the worker's connections, which costs it almost nothing, would lock everyone else
     * out. The oldest first of: those already answered (lingering before the close, or skipping
     * an unread body), then those that never completed a request (a new connection that sent
     * nothing yet, a slow head), then the kept-alive ones, and last those waiting for more of a
     * request body. Upgraded connections (101), and connections waiting for the application to
     * read its request body after the response, are never closed to make room.
     */
    private function reclaim(): void
    {
        foreach ([NativeHttpConnection::ANSWERED, NativeHttpConnection::NEW, NativeHttpConnection::KEPT_ALIVE, NativeHttpConnection::BODY] as $state) {
            foreach ($this->connections as $connection) {
                if ($connection->reclaim($state)) {
                    ++$this->reclaimed;

                    return;
                }
            }
        }
    }

    /**
     * A warning that the worker runs short, at most once a minute: under a flood, every accept
     * would log one.
     */
    private function logShort(string $message, array $context): void
    {
        $now = \microtime(true);
        if ($now - $this->shortLogged >= 60) {
            $this->shortLogged = $now;
            $this->logger->warning($message, $context);
            $this->reclaimed = 0;
        }
    }
}
