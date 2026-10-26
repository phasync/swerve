<?php

namespace Swerve\Lot;

use phasync;
use phasync\CancelledException;
use phasync\IOException;
use phasync\Net\StreamDuplex;
use phasync\TimeoutException;
use Swerve\Accepted;
use Swerve\ClientRequest;
use Swerve\Http\HttpClientRequest;
use Swerve\Http\HttpConnection;
use Swerve\StaticFiles;
use Swerve\Swerve;
use Swerve\Util\ObjectPoolTrait;

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
 * - worker 'R' + connection: back, kept alive, with its next request waiting.
 * - worker 'B' + connection: back, kept alive and idle: the lifecycle ended before its next request.
 *
 * And to the WebSocket worker, over the socket on file descriptor 4 (see {@see WebSocketWorker}):
 *
 * - worker 'W' + a serialized [closure, subprotocol] + connection: an open WebSocket, its `101` sent.
 *
 * @internal
 */
final class LotClient
{
    private const FD = 3;

    /** The socket to the WebSocket worker. */
    private const WEBSOCKETS_FD = 4;

    /** Requests in a row this worker serves on a connection; then it goes back to the master. */
    private const KEEP_LIMIT = 1_000_000;

    /** Seconds a kept-alive connection may wait for its next request (as in Node). */
    private const KEEP_ALIVE_TIMEOUT = 5.0;

    private static ?self $instance = null;

    /** @var resource */
    private $channel;

    private \Socket $socket;

    /** @var list<resource> from the master, with a request waiting, not yet served here */
    private array $stash = [];

    /** @var list<Offer> served here, with their next request waiting, for the next accept() */
    private array $ready = [];

    /** @var array<int, resource> being served, by resource id */
    private array $busy = [];

    /**
     * @var array<int, array{HttpConnection, \Fiber}> each connection's HttpConnection and
     *                                                coroutine, while it runs, by resource id
     */
    private array $connections = [];

    /** @var array<int, resource> kept alive, waiting for their next request, by resource id */
    private array $idle = [];

    /** accept() calls in this lifecycle. */
    private int $accepts = 0;

    /** accept() calls waiting for a connection. */
    private int $accepting = 0;

    /** An 'N' is unanswered. */
    private bool $asking = false;

    /** The service receiving the master's answers, while one runs. */
    private ?\Fiber $reader = null;

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
        // Registered from a shutdown function, so it runs after the application's
        \register_shutdown_function(fn () => \register_shutdown_function($this->shutdown(...)));
        self::send($this->socket, 'H');
        while ('h' !== ([$type, $conn] = $this->receiveOrFail())[0]) {
            $this->stash[] = \socket_export_stream($conn);
        }
    }

    /**
     * Serve the next request with $handler: on a connection served here whose next request
     * arrived, else on one from the master. Waits for one asynchronously.
     *
     * @param \Closure(\Swerve\ClientRequest): void $handler
     */
    public function accept(\Closure $handler, ?StaticFiles $files): Accepted
    {
        ++$this->accepts;
        $request = new Accepted($handler, $files);
        $this->assign($request);

        return $request;
    }

    /**
     * accept() was called once so far: the lifecycle may be one that ends after each request
     * (WordPress's), which a connection can't outlive.
     */
    public function shortLived(): bool
    {
        return 1 === $this->accepts;
    }

    /**
     * Move $client's connection, its `101` sent, to the WebSocket worker, with $closure (a
     * serialized [closure, subprotocol]); the request ends here, without closing it.
     */
    public function moveWebSocket(HttpClientRequest $client, string $closure): void
    {
        foreach ($this->connections as $id => [$http]) {
            if ($http->current() === $client) {
                break;
            }
        }
        // The client waits for the 101 before it writes (RFC 6455, 4.1): bytes read already broke
        // that, and can't go along: the connection closes
        if ($http->bufferEmpty()) {
            $stream = @\fopen('php://fd/' . self::WEBSOCKETS_FD, 'r+');
            if (false === $stream) {
                throw new \LogicException('No WebSocket worker to move the connection to: run the application with `swerve`');
            }
            try {
                self::send(\socket_import_stream($stream), "W$closure", $this->busy[$id]);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Moving a WebSocket (' . \strlen($closure) . ' bytes serialized) to the WebSocket worker failed; a closure that captures less moves', 0, $e);
            } finally {
                \fclose($stream);
            }
        }
        $client->moved();
    }

    /** Give $request a connection with a request waiting: one served here, else one from the master. */
    private function assign(Accepted $request): void
    {
        ++$this->accepting;
        try {
            while (true) {
                if ([] !== $this->ready) {
                    $offer                                = \array_shift($this->ready);
                    $this->busy[(int) $offer->stream]     = $offer->stream;
                    $offer->request                       = $request;
                    phasync::raiseFlag($offer);

                    return;
                }
                if ([] !== $this->stash) {
                    $stream                     = \array_shift($this->stash);
                    $this->busy[(int) $stream]  = $stream;
                    phasync::service($this->connection(...), $stream, $request);

                    return;
                }
                // The reader first: a service runs at once, and one started after the 'N' could
                // take the answer, and raise the flag, before this waits for it
                $this->reader ??= phasync::service($this->read(...));
                if (!$this->asking) {
                    self::send($this->socket, 'N');
                    $this->asking = true;
                }
                phasync::awaitFlag($this);
            }
        } finally {
            --$this->accepting;
        }
    }

    /**
     * A connection's coroutine, for as long as the connection is in this worker. It serves
     * static files for as long as the client has its next request written, and the first other
     * request with the accept()'s application; static files don't use up the accept(), which
     * goes on with another connection when this one goes idle or closes after files only. After
     * the application's response it waits for the next request, and serves that too when an
     * accept() waits for one; else the connection goes back to the master with its request
     * waiting.
     *
     * @param resource $stream
     */
    private function connection($stream, Accepted $request): void
    {
        $id                     = (int) $stream;
        $http                   = new HttpConnection(new StreamDuplex($stream), $request->handler, Swerve::log(), null);
        $this->connections[$id] = [$http, \Fiber::getCurrent()];
        try {
            $this->serveConnection($stream, $http, $request);
        } finally {
            // Not when exit() ends the lifecycle (it runs no finally): shutdown() finds it then
            unset($this->connections[$id]);
        }
    }

    /**
     * @param resource $stream
     */
    private function serveConnection($stream, HttpConnection $http, Accepted $request): void
    {
        $id     = (int) $stream;
        $served = 0;
        $for    = null;
        while (true) {
            if ($for !== $request) {
                // The application's handler, which notes that the request was not a file
                $handler       = static function (ClientRequest $client) use ($request, &$application): void {
                    $application = true;
                    if ($client instanceof HttpClientRequest) {
                        $client->onHandOff = $request->done(...);
                    }
                    try {
                        ($request->handler)($client);
                    } catch (\Throwable $e) {
                        $request->fail($e); // for whoever awaits the request; the 500 and the log as always
                        throw $e;
                    }
                };
                $http->handler = null === $request->files ? $handler : $request->files->wrap($handler);
                $for           = $request;
            }
            $application = false;
            $open        = $http->serve(one: true);
            unset($this->busy[$id]);
            if (!$application) {
                // A static file doesn't use up the accept(): it goes on with this connection's next
                // request if the client has written it, else with another connection
                if ($open && ++$served < self::KEEP_LIMIT && $http->pending()) {
                    $this->busy[$id] = $stream;

                    continue;
                }
                if ($open) {
                    $this->handBack($http, $stream);
                }
                $this->assign($request);

                return;
            }
            $request->done();
            if (!$open) {
                return; // closed
            }
            if (!$http->bufferEmpty()) {
                // The next request is read already (pipelined): only this worker can serve it
                $this->ready[] = $offer = Offer::take($stream, true);
                phasync::raiseFlag($this);
                try {
                    phasync::awaitFlag($offer);
                } catch (CancelledException) {
                    return; // the lifecycle ends: shutdown() closes it
                }
                $request = $offer->request;
                $offer->returnToPool();

                continue;
            }
            $this->idle[$id] = $stream;
            try {
                phasync::readable($stream, self::KEEP_ALIVE_TIMEOUT);
            } catch (CancelledException) {
                return; // the lifecycle ends: shutdown() hands it back
            } catch (IOException|TimeoutException) {
                unset($this->idle[$id]);
                \fclose($stream);

                return;
            }
            unset($this->idle[$id]);
            if ('' === \stream_socket_recvfrom($stream, 1, \STREAM_PEEK)) {
                \fclose($stream); // readable because the client closed it

                return;
            }
            if (($application && ++$served >= self::KEEP_LIMIT) || 0 === $this->accepting) {
                $this->giveBack($stream, 'R');

                return;
            }
            $this->ready[] = $offer = Offer::take($stream, false);
            phasync::raiseFlag($this);
            try {
                phasync::awaitFlag($offer);
            } catch (CancelledException) {
                return; // the lifecycle ends: shutdown() hands it back
            }
            $request = $offer->request;
            $offer->returnToPool();
        }
    }

    /** The master's answers to 'N': connections with a request waiting. */
    private function read(): void
    {
        try {
            while (true) {
                [$type, $conn] = $this->receiveOrFail();
                if ('C' !== $type || null === $conn) {
                    throw new \UnexpectedValueException("The master answered '$type' to a worker's ask");
                }
                $this->asking  = false;
                $this->stash[] = \socket_export_stream($conn);
                phasync::raiseFlag($this);
            }
        } finally {
            $this->reader = null;
        }
    }

    /**
     * The lifecycle ends, after the application's shutdown functions: kept-alive and unserved
     * connections go back to the master. A request that ended the lifecycle with exit() has its
     * response finished, as under php-fpm, and its connection goes back too when it is clean.
     * Others still being served (cut short) are closed.
     */
    private function shutdown(): void
    {
        foreach ($this->busy as $id => $conn) {
            [$http, $fiber] = $this->connections[$id] ?? [null, null];
            $request        = $http?->current();
            // exit() unwound the coroutine of the request it ended; the others are suspended
            if (null === $request || !$fiber->isTerminated()) {
                continue;
            }
            try {
                $open = phasync::run($request->complete(...));
            } catch (\Throwable) {
                $open = false;
            }
            unset($this->busy[$id]);
            if ($open) {
                $this->handBack($http, $conn);
            } else {
                \fclose($conn);
            }
        }
        foreach ($this->idle as $conn) {
            $this->giveBack($conn, 'B');
        }
        foreach ($this->ready as $offer) {
            if ($offer->buffered) {
                \fclose($offer->stream); // what was read here can't go with the socket
            } else {
                $this->giveBack($offer->stream, 'R');
            }
        }
        foreach ($this->stash as $conn) {
            $this->giveBack($conn, 'R');
        }
        foreach ($this->busy as $conn) {
            \fclose($conn);
        }
        $this->idle = $this->ready = $this->stash = $this->busy = [];
    }

    /**
     * Hand a kept-alive connection back to the master: 'R' with its next request waiting, 'B'
     * idle, for the master to wait on.
     *
     * @param resource $conn
     */
    private function giveBack($conn, string $type): void
    {
        self::send($this->socket, $type, $conn);
        \fclose($conn);
    }

    /**
     * A kept-alive connection leaves this worker: back to the master, 'R' with its next request
     * waiting or 'B' idle; closed when its next request is read already, as that can't go along.
     *
     * @param resource $conn
     */
    private function handBack(HttpConnection $http, $conn): void
    {
        if (!$http->bufferEmpty()) {
            \fclose($conn);
        } else {
            $this->giveBack($conn, $http->io->pending() ? 'R' : 'B');
        }
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
     * The next packet: [its bytes (a letter, unless $size is larger), the connection with it or
     * null]; null once the other side is gone.
     *
     * @param resource $channel the stream $socket was imported from, to wait on
     * @param int      $size    the largest packet: a larger one is cut short
     *
     * @return array{0: string, 1: ?\Socket}|null
     */
    public static function receive($channel, \Socket $socket, int $size = 1): ?array
    {
        while (true) {
            $message = ['buffer_size' => $size, 'controllen' => \socket_cmsg_space(\SOL_SOCKET, \SCM_RIGHTS, 1)];
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
 * A connection served here whose next request arrived, waiting for an accept() to take it.
 *
 * @internal
 */
final class Offer
{
    use ObjectPoolTrait;

    public ?Accepted $request = null;

    /** @var resource */
    public mixed $stream;

    /** Its next request is read already: it can't go back to the master. */
    public bool $buffered;

    private function __construct()
    {
    }

    /** @param resource $stream */
    public static function take($stream, bool $buffered): self
    {
        $offer           = self::popInstance() ?? new self();
        $offer->stream   = $stream;
        $offer->buffered = $buffered;

        return $offer;
    }

    /** accept() took it. */
    public function returnToPool(): void
    {
        $this->request = null;
        unset($this->stream);
        $this->pushInstance();
    }
}
