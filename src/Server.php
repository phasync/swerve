<?php

namespace Swerve;

use phasync;
use phasync\CancelledException;
use phasync\Frame;
use phasync\Net\StreamDuplex;
use phasync\ShutdownException;
use phasync\TimeoutException;
use Psr\Log\LoggerInterface;
use Swerve\Http\Handler;
use Swerve\Http\HttpConnection;
use Swerve\Lot\Lot;
use Swerve\Util\Shutdown;

/**
 * An HTTP server in the application's own process: a CLI program with a web interface, a test,
 * a tool that serves a page while it works. It serves the handlers {@see Swerve::accept()}
 * takes, concurrently, in coroutines of this process, next to whatever else the program runs:
 * what the program holds, its requests share.
 *
 * ```php
 * phasync::run(function () {
 *     $web = new Swerve\Server('127.0.0.1:8080', $app->handle(...), __DIR__ . '/public');
 *     $tui->run();      // the program's own work, here a terminal UI
 *     $web->close();    // its requests end, then close() returns
 * });
 * ```
 *
 * A request's code runs as it does in a swerve worker: in a `Scope` of its own, with
 * {@see Swerve::upgrade()}, {@see Swerve::finishRequest()}, WebSockets and server-sent events,
 * and with {@see Swerve::onShutdown()}, {@see Swerve::awaitShutdown()} and
 * {@see Swerve::draining()} telling it when this server closes. A server has one process: no
 * workers, so no reload, no recycling, and a request that fails fatally ends the program; it
 * suits code that waits asynchronously (phasync, or any loop on fiber-interop/api). Moving a
 * WebSocket to WebSocket workers (upgrade() with a bootstrap file) needs `swerve`.
 *
 * Needs ext-phasync, as swerve does. Log messages go to the logger given, else to
 * {@see Swerve::log()}, which writes to STDERR: a terminal UI gives its own.
 *
 * @topic embedding
 */
final class Server
{
    /**
     * Seconds the requests have after close() to end by themselves; then their coroutines get a
     * {@see WorkerStoppingException} (a ShutdownException).
     */
    private const CLOSE_GRACE = 5.0;

    /** Where the server listens: `host:port` (the port chosen when 0 was asked), or `unix:/path`. */
    public readonly string $address;

    /** @var resource */
    private $listener;

    private readonly \Closure $handler;
    private readonly ?StaticFiles $files;
    private readonly LoggerInterface $log;
    private readonly Shutdown $shutdown;

    /** The socket file of a unix: address, removed by close(). */
    private ?string $path = null;

    /** @var array<int, HttpConnection> the open connections, by resource id */
    private array $connections = [];

    /** Raised when a connection ends: room under the limit, and close() looks again. */
    private \stdClass $ended;

    /** The coroutine that accepts, in the frame that holds every connection's coroutine. */
    private \Fiber $acceptor;
    private Frame $frame;

    /** The coroutine around that frame: done once every connection ended. */
    private \Fiber $runner;

    private bool $closed = false;

    /**
     * Listen on $address and serve from now on, until close().
     *
     * @param string               $address    `host:port` (`127.0.0.1:8080`, `[::1]:0` for a free port), or `unix:/path`
     * @param string|\Closure|null $handler    as {@see Swerve::accept()} takes it: a closure taking a
     *                                         ServerRequestInterface, or the ClientRequest, or no
     *                                         parameters; a script's path; null for $staticPath alone
     * @param ?string              $staticPath the directory of static files (as {@see StaticFiles}); null: none
     * @param ?LoggerInterface     $log        where failed requests are logged; null: {@see Swerve::log()}
     *
     * @throws \RuntimeException when it cannot listen on $address
     * @throws \LogicException   outside a coroutine
     */
    public function __construct(string $address, string|\Closure|null $handler, ?string $staticPath = null, ?LoggerInterface $log = null)
    {
        $files          = null === $staticPath ? null : new StaticFiles($staticPath);
        $this->files    = $files;
        $this->log      = $log ?? Swerve::log();
        $this->shutdown = new Shutdown();
        $this->ended    = new \stdClass();
        $shutdown       = $this->shutdown;
        $serve          = Handler::from($handler, $files?->root);
        // The request's Scope belongs to this server, for Swerve::onShutdown() and the like
        $this->handler  = static function (ClientRequest $client) use ($serve, $shutdown): void {
            Shutdown::serving($shutdown);
            $serve($client);
        };

        $socket = "tcp://$address";
        if (\str_starts_with($address, 'unix:')) {
            $this->path = \preg_replace('#^unix:(?://)?#', '', $address);
            $socket     = "unix://{$this->path}";
        }
        $listener = @\stream_socket_server($socket, $errno, $error, context: \stream_context_create(['socket' => ['backlog' => 65535]]));
        if (false === $listener) {
            throw new \RuntimeException("Swerve\\Server cannot listen on $address: " . ($error ?: \error_get_last()['message'] ?? ''));
        }
        \stream_set_blocking($listener, false);
        if (null === $this->path && !\socket_set_option(\socket_import_stream($listener), \SOL_TCP, \TCP_NODELAY, 1)) {
            throw new \RuntimeException('Setting TCP_NODELAY on the listener failed');
        }
        $this->listener = $listener;
        $this->address  = null === $this->path ? \stream_socket_get_name($listener, false) : "unix:{$this->path}";
        // The frame holds the acceptor and every connection's coroutine, and waits for them; the
        // acceptor is not its main coroutine, whose wait for the others close() must not cancel
        $this->runner   = phasync::go(fn () => phasync::run(function () {
            $this->frame    = phasync::frame();
            $this->acceptor = phasync::go($this->accept(...));
        }));
    }

    /**
     * Stop serving: no new connection is accepted, those waiting for a request close, the
     * {@see Swerve::onShutdown()} callbacks of this server's requests run and its
     * {@see Swerve::awaitShutdown()} calls return. The requests have a few seconds to end
     * (a WebSocket says goodbye); then their coroutines are cancelled. Returns once every
     * connection ended. Once closed, a server stays closed: start a new one to serve again.
     *
     * @throws \LogicException outside a coroutine
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        phasync::cancel($this->acceptor);
        $this->shutdown->fire();
        foreach ($this->connections as $http) {
            $http->closing = true;
            foreach ([HttpConnection::ANSWERED, HttpConnection::NEW, HttpConnection::KEPT_ALIVE] as $state) {
                if ($http->reclaim($state)) {
                    break;
                }
            }
        }
        try {
            phasync::await($this->runner, self::CLOSE_GRACE);
        } catch (TimeoutException) {
            $this->frame->cancel(new WorkerStoppingException(StopReason::Shutdown));
            try {
                phasync::await($this->runner);
            } catch (ShutdownException) {
            }
        }
    }

    /** Accept connections, each served in a coroutine of this frame, until close(). */
    private function accept(): void
    {
        $max = Lot::connectionLimit();
        try {
            while (true) {
                if (\count($this->connections) >= $max) {
                    phasync::awaitFlag($this->ended);

                    continue;
                }
                phasync::readable($this->listener);
                while (\count($this->connections) < $max && false !== $conn = @\stream_socket_accept($this->listener, 0)) {
                    \stream_set_blocking($conn, false);
                    $http        = new HttpConnection(new StreamDuplex($conn), $this->handler, $this->log, null);
                    $http->files = $this->files;
                    $id          = (int) $conn;
                    $this->connections[$id] = $http;
                    phasync::go(function () use ($http, $id) {
                        try {
                            $http->serve();
                        } finally {
                            unset($this->connections[$id]);
                            phasync::raiseFlag($this->ended);
                        }
                    });
                }
            }
        } catch (CancelledException) {
            \fclose($this->listener);
            if (null !== $this->path) {
                @\unlink($this->path);
            }
        }
    }
}
