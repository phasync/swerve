<?php

namespace Swerve\Http;

use phasync;
use Psr\Log\LoggerInterface;
use Swerve\Dispatcher;
use Swerve\ServerInterface;
use Swerve\Util\System;

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

    /** @var resource|null */
    private $listener = null;

    /** The listener as ext-sockets' object, when System::hasSockets(): connections are accepted with it. */
    private ?\Socket $listenSocket = null;
    /** A unix: listener is one socket for every worker: see waitForClient() */
    private bool $shared = false;
    private ?\Fiber $waiting = null;
    private ?\RuntimeException $wake = null;

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
     * @param int              $maxBodySize the largest request body (413); PHP_INT_MAX for no limit
     * @param ?TrustedProxies  $proxies     the proxies whose X-Forwarded-* headers are believed
     */
    public function __construct(
        private readonly string $address,
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly bool $bufferResponses = false,
        private readonly int $maxBodySize = HttpConnection::MAX_BODY,
        private readonly ?TrustedProxies $proxies = null,
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

        // Not phasync\Net\listen(): its accept loop retries at once, forever, when accepting
        // fails for lack of descriptors; and its listener is inherited by processes the
        // application starts, see System::listen()
        $listener       = System::listen($this->address);
        $this->listener = $listener;
        $this->shared   = \str_starts_with($this->address, 'unix:');
        if (System::hasSockets()) {
            $this->listenSocket = \socket_import_stream($listener);
        }
        $this->logger->info('Serving HTTP at {address}, at most {max} connections', ['address' => \stream_socket_get_name($listener, false), 'max' => $this->max]);
    }

    /**
     * Accept and serve connections until drained: returns once drain() was called and every
     * connection has ended. Call from inside phasync::run(), after listen().
     */
    public function run(): void
    {
        $this->running = true;
        try {
            $ready = false;
            while (true) {
                // A burst of connections is accepted without waiting on the event loop in between
                $socket = $this->accept($peer, $sock);
                if (false === $socket) {
                    if ($ready && $this->shared) {
                        // Another worker may have accepted it first: a failure only if one is still waiting
                        $r     = [$this->listener];
                        $w     = $x = null;
                        $ready = \stream_select($r, $w, $x, 0) > 0;
                    }
                    if ($ready) {
                        // A connection was waiting, yet accepting it failed: out of descriptors
                        $this->logShort('Accepting a connection failed ({error}); retrying', ['error' => \error_get_last()['message'] ?? 'unknown error']);
                        \error_clear_last();
                        phasync::sleep(0.1);
                    } else {
                        \error_clear_last(); // nothing was waiting: not an error for the application to see
                    }
                    $this->waitForClient();
                    if ($this->draining) {
                        break;
                    }
                    $ready = true;
                    continue;
                }
                $ready = false;
                $this->adopt($socket, $peer, $sock);
                // At the limit: once a client waits to be accepted, make room for it
                while (\count($this->connections) >= $this->max) {
                    $this->waitForClient();
                    if ($this->draining) {
                        break 2;
                    }
                    if (\count($this->connections) >= $this->max) {
                        $this->reclaim();
                        $this->logShort('At the limit of {max} connections; {n} waiting on their clients closed to make room since the last warning; {remedy}', ['max' => $this->max, 'n' => $this->reclaimed, 'remedy' => $this->remedy]);
                        phasync::awaitFlag($this);
                        if ($this->draining) {
                            break 2;
                        }
                    }
                }
            }
            // Closed here, not in drain(): this coroutine was waiting on it
            \fclose($this->listener);
            $this->listener     = null;
            $this->listenSocket = null;
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
     *
     * With `$linger` (a recycle), the upgraded connections are left alone: they go on until
     * they close, and run() returns after the last. Calling drain() again, without `$linger`,
     * ends them as above.
     */
    public function drain(bool $linger = false): void
    {
        $first = !$this->draining;
        if (!$first) {
            // Only the connections: the listener is closed
        } elseif ($this->shared) {
            $this->draining = true;
            if ($this->waiting) {
                phasync::throw($this->waiting, $this->wake = new \RuntimeException('drain'));
            }
        } else {
            while ($socket = $this->accept($peer, $sock)) {
                $this->adopt($socket, $peer, $sock); // may let run() go on for a while, still accepting
            }
            \error_clear_last(); // the queue is empty: not an error for the application to see
            // From here on nothing suspends until run() is woken by the shutdown
            $this->draining = true;
            \stream_socket_shutdown($this->listener, \STREAM_SHUT_RD);
        }
        $upgraded = 0;
        foreach ($this->connections as $connection) {
            $upgraded += (int) $connection->drain($linger);
        }
        phasync::raiseFlag($this); // run() may wait for a free place
        $this->logger->info($first ? 'Draining HTTP at {address}: {n} connections open, {upgraded} upgraded' : 'Closing HTTP at {address}: {n} connections open, {upgraded} upgraded', ['address' => $this->address, 'n' => \count($this->connections), 'upgraded' => $upgraded]);
    }

    /**
     * Wait for a client. The listener of a unix: address is shared with the other workers, so
     * drain() can't shut it down to wake this: an exception is thrown into its wait coroutine instead.
     */
    private function waitForClient(): void
    {
        if (!$this->shared) {
            phasync::readable($this->listener, \PHP_FLOAT_MAX);

            return;
        }
        // run() waits in other places too, where drain()'s exception must not land:
        // the wait is a coroutine of its own, which is the one drain() throws into
        $this->waiting = phasync::go(fn () => phasync::readable($this->listener, \PHP_FLOAT_MAX));
        try {
            phasync::await($this->waiting);
        } catch (\RuntimeException $e) {
            if ($e !== $this->wake) {
                throw $e;
            }
        } finally {
            $this->waiting = null;
        }
    }

    /**
     * The next waiting connection, or false. With ext-sockets it arrives already non-blocking and
     * close-on-exec, and as a stream only for waiting on it: $sock is what the connection reads
     * and writes with.
     *
     * @return resource|false
     */
    private function accept(?string &$peer, ?\Socket &$sock): mixed
    {
        if (null === $this->listenSocket) {
            $sock = null;

            return @\stream_socket_accept($this->listener, 0, $peer);
        }
        $accepted = @\socket_accept($this->listenSocket);
        if (false === $accepted) {
            $sock = null;

            return false;
        }
        $sock = $accepted;
        if ($this->shared) {
            $peer = ''; // a unix socket's client has no address
        } else {
            \socket_getpeername($sock, $address, $port);
            $peer = (\str_contains($address, ':') ? "[$address]" : $address) . ":$port";
        }

        return \socket_export_stream($sock);
    }

    /**
     * @param resource $socket
     */
    private function adopt($socket, string $peer, ?\Socket $sock): void
    {
        null === $sock ? \stream_set_blocking($socket, false) : \socket_set_nonblock($sock);
        $connection = new HttpConnection($socket, $peer, $this->dispatcher, $this->logger, $this->bufferResponses, $this->maxBodySize, $sock, $this->proxies);
        $id         = \spl_object_id($connection);

        $this->connections[$id] = $connection;
        phasync::go(function () use ($connection, $id) {
            try {
                $connection->serve();
            } finally {
                $full = $this->max === \count($this->connections);
                unset($this->connections[$id]);
                if ($this->running && ($full || $this->draining)) {
                    phasync::raiseFlag($this); // run() waits for a free place, or for the last one
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
