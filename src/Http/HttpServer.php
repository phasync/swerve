<?php

namespace Swerve\Http;

use phasync;
use phasync\IOException;
use phasync\Net\Listener;
use phasync\Net\Server;
use Psr\Log\LoggerInterface;
use Swerve\ServerInterface;
use Swerve\Util\Logger;
use Swerve\Util\System;

/**
 * HTTP mode (--http): a worker serving HTTP/1.1 itself, with no proxy in front. Every worker
 * listens on the same address (SO_REUSEPORT), and the kernel spreads new connections
 * over them. Connections are taken from a phasync\Net\Server, and each is served by an
 * {@see HttpConnection}.
 *
 * A worker serves at most so many connections at once that its file descriptors stay below
 * the limit (see listen()); more wait in the kernel's accept queue. At that limit, connections
 * waiting on their client are closed to make room, see reclaim().
 *
 * @internal
 */
final class HttpServer implements ServerInterface
{
    /**
     * File descriptors left for the listener, logs and the application's own files and
     * connections, below the connection limit. (Without phasync-ext the limit is half of
     * FD_SETSIZE, which leaves far more.)
     */
    private const RESERVED_FDS = 64;

    /** The native stream_select() fails outright for a file descriptor at or above this. */
    private const FD_SETSIZE = \PHP_FD_SETSIZE;

    /**
     * Connections being served, oldest first.
     *
     * @var array<int, HttpConnection>
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

    /** At the limit, makeRoom() waits for the next connection to come or go. */
    private bool $makingRoom = false;

    /** When running short (of connections, of descriptors) was last logged: once a minute at most. */
    private float $shortLogged = -\INF;

    /** Connections closed to make room since the last log of it, see reclaim(). */
    private int $reclaimed = 0;

    private readonly ?Logger $access;

    /**
     * @param \Closure(\Swerve\ClientRequest): void $handler     the application's
     * @param int                                   $maxBodySize the largest request body (413); PHP_INT_MAX for no limit
     * @param ?TrustedProxies                       $proxies     the proxies whose X-Forwarded-* headers are believed
     */
    public function __construct(
        private readonly string $address,
        private readonly \Closure $handler,
        private readonly LoggerInterface $logger,
        private readonly int $maxBodySize = HttpConnection::MAX_BODY,
        private readonly ?TrustedProxies $proxies = null,
    ) {
        $this->access = $logger instanceof Logger && $logger->access ? $logger : null;
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
     *
     * The application opens descriptors of its own while serving, about one per client on
     * average, so without the extension a worker serves at most half of FD_SETSIZE connections,
     * leaving the other half for those and for the listener, logs and pipes. With the extension
     * the limit is the open-file limit less RESERVED_FDS.
     */
    public function listen(): void
    {
        $rlimit = \posix_getrlimit();
        $limit  = (int) $rlimit['soft openfiles'];
        if (!\extension_loaded('phasync') && $limit > self::FD_SETSIZE) {
            $hard = 'unlimited' === $rlimit['hard openfiles'] ? \POSIX_RLIM_INFINITY : (int) $rlimit['hard openfiles'];
            if (!\posix_setrlimit(\POSIX_RLIMIT_NOFILE, self::FD_SETSIZE, $hard)) {
                throw new \RuntimeException('Could not lower the open-file limit to ' . self::FD_SETSIZE);
            }
            $limit        = self::FD_SETSIZE;
            $this->remedy = 'add workers, or install the phasync extension: without it a worker serves no more, whatever the open-file limit';
        }
        $this->max = $limit - self::RESERVED_FDS;
        if (!\extension_loaded('phasync')) {
            $this->max = \min($this->max, \intdiv(self::FD_SETSIZE, 2));
        }
        \register_shutdown_function(static function () { HttpConnection::$exiting = true; });

        $options = ['max_connections' => $this->max];
        // A unix: listener is one socket for every worker, made before they were forked
        $this->server = \str_starts_with($this->address, 'unix:')
            ? new Server(Listener::fromStream(System::listen($this->address)), $options)
            : new Server($this->address, $options);
        $this->logger->info('Serving HTTP at {address}, at most {max} connections', ['address' => $this->server->addr(), 'max' => $this->max]);
    }

    /**
     * Accept and serve connections until drained: returns once drain() was called and every
     * connection has ended. Call from inside phasync::run(), after listen().
     */
    public function run(): void
    {
        $this->running = true;
        try {
            phasync::go($this->makeRoom(...));
            while (true) {
                try {
                    $io = $this->server->accept();
                } catch (IOException) {
                    break; // drain() closed the server
                }
                $this->adopt($io);
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
     * first; the listener then leaves the SO_REUSEPORT group at once, so the other workers get
     * every new connection. With net.ipv4.tcp_migrate_req=0, a handshake that completes in the
     * microseconds between the two is reset; with 1, the kernel moves it to another worker's
     * listener.
     *
     * With `$linger` (a recycle), the upgraded connections are left alone: they go on until
     * they close, and run() returns after the last. Calling drain() again, without `$linger`,
     * ends them as above.
     */
    public function drain(bool $linger = false): void
    {
        $first = !$this->draining;
        if ($first) {
            $this->draining = true;
            $this->server->close();
        }
        $upgraded = 0;
        foreach ($this->connections as $connection) {
            $upgraded += (int) $connection->drain($linger);
        }
        phasync::raiseFlag($this); // makeRoom() and run() wait on it
        $this->logger->info($first ? 'Draining HTTP at {address}: {n} connections open, {upgraded} upgraded' : 'Closing HTTP at {address}: {n} connections open, {upgraded} upgraded', ['address' => $this->address, 'n' => \count($this->connections), 'upgraded' => $upgraded]);
    }

    private function adopt(\phasync\Net\Duplex $io): void
    {
        $connection = new HttpConnection($io, $this->handler, $this->logger, $this->access, $this->maxBodySize, $this->proxies);
        $id         = \spl_object_id($connection);

        $this->connections[$id] = $connection;
        if ($this->draining) {
            $connection->drain(); // accepted from the queue after the drain began
        }
        phasync::go(function () use ($connection, $id) {
            try {
                $connection->serve();
            } finally {
                unset($this->connections[$id]);
                if ($this->running && ($this->makingRoom || $this->draining)) {
                    phasync::raiseFlag($this); // makeRoom() waits for a connection to go, or run() for the last one
                }
            }
        });
        if ($this->makingRoom) {
            phasync::raiseFlag($this);
        }
    }

    /**
     * At the limit, with a client waiting to be accepted: make room by closing a connection,
     * then wait for the client to be accepted (or another connection to end) before looking
     * again.
     */
    private function makeRoom(): void
    {
        try {
            while (!$this->draining) {
                $this->server->awaitFull();
                $this->reclaim();
                $this->logShort('At the limit of {max} connections; {n} waiting on their clients closed to make room since the last warning; {remedy}', ['max' => $this->max, 'n' => $this->reclaimed, 'remedy' => $this->remedy]);
                $this->makingRoom = true;
                phasync::awaitFlag($this);
                $this->makingRoom = false;
            }
        } catch (IOException) {
            // drain() closed the server
        } finally {
            $this->makingRoom = false;
        }
    }

    /**
     * Close a connection that waits on its client, as nginx reuses its idle keep-alive
     * connections when it runs short. Else a client holding the worker's connections, which
     * costs it almost nothing, would lock everyone else out. The oldest first of: those already
     * answered (lingering before the close, or skipping an unread body), then those that never
     * completed a request (a new connection that sent nothing yet, a slow head), then the
     * kept-alive ones, and last those waiting for more of a request body. Upgraded connections
     * (101) are never closed to make room.
     */
    private function reclaim(): void
    {
        foreach ([HttpConnection::ANSWERED, HttpConnection::NEW, HttpConnection::KEPT_ALIVE, HttpConnection::BODY] as $state) {
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
