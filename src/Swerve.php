<?php

namespace Swerve;

use phasync;
use phasync\Psr\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swerve\Http\HttpConnection;
use Swerve\Http\TrustedProxies;
use Swerve\Psr\Bridge;
use Swerve\Util\Logger;
use Swerve\Util\Router;
use Swerve\Util\Sapi;
use Swerve\Util\Shutdown;
use Swerve\Util\StaticFile;
use Swerve\Util\Topics;
use Swerve\Util\Worker;
use Swerve\Util\WorkerDuplex;

/**
 * What swerve gives the application: its log, the cache and the messages shared by every worker, and the state of the worker.
 *
 * Every method is static. Workers share no memory: the cache and the messages go through the
 * master process. A coroutine that `swerve.php` starts waits for the worker to serve before its
 * first call goes through; directly in `swerve.php` the cache and publishing throw (see docs/bootstrap.md).
 *
 * ```php
 * use Swerve\Swerve;
 *
 * Swerve::log()->info('started');
 * Swerve::cache()->set('hits', 1, 60);
 * Swerve::publish('chat', ['user' => 'ann', 'text' => 'hi']);
 * ```
 *
 * @see Swerve\Cache
 * @see Swerve\Subscription
 *
 * @topic request-handlers
 *
 * @example examples/Swerve/1-one-process.php Messages and the cache within one process
 */
final class Swerve
{
    private function __construct()
    {
    }

    /** The log of this process, see log(). */
    private static ?LoggerInterface $log = null;

    /** @var array<string, string> the php.ini settings packages registered with ini() */
    private static array $ini = [];

    /** @var list<\Closure> the onWorkerStart() callbacks */
    private static array $workerStart = [];

    /** Startup is over: later registrations change nothing */
    private static bool $started = false;

    /** Requests already parsed (pipelined, or from a connection accept() kept itself), see accept(). */
    private static array $queue = [];
    /** Raised when accept() pushes to $queue. */
    private static ?\stdClass $queueFlag = null;
    /** Calls to accept() currently blocked with nothing to do: a worker with spare capacity, see onIdle(). */
    private static int $idle = 0;
    /** The background coroutine pulling connections (see accept()) was started once already. */
    private static bool $acceptorStarted = false;
    /** The fiber running each request's handler, so a nested accept() from inside it can be refused. */
    private static ?\WeakMap $requestFibers = null;
    /** 'R' sent once, at the first accept(). */
    private static bool $readySent = false;
    /** A closure's handler form, cached: see resolveHandler(). */
    private static ?\WeakMap $handlerKinds = null;
    /** $_SERVER as the application left it after booting (its own values, not the command line's), see bootServer(). */
    private static ?array $bootServer = null;
    private static int $maxBody = HttpConnection::MAX_BODY;
    private static ?TrustedProxies $trustedProxies = null;
    private static ?Logger $accessLog = null;

    /**
     * Open HTTP connections, oldest first, keyed by spl_object_id(); see connectionLimit()
     * and reclaim().
     *
     * @var array<int, HttpConnection>
     */
    private static array $connections = [];
    /** The most connections this worker serves at once, see connectionLimit(). */
    private static int $maxConnections = \PHP_INT_MAX;
    /** What serves more connections, for the log; see connectionLimit() and reclaim(). */
    private static string $remedy = 'raise the open-file limit (ulimit -n) or add workers';
    /** When running short of connections was last logged: once a minute at most. */
    private static float $shortLogged = -\INF;
    /** Connections closed to make room since the last log of it, see reclaim(). */
    private static int $reclaimed = 0;

    /**
     * File descriptors left for the listener, logs and the application's own files and
     * connections, below the connection limit. (Without ext-phasync the limit is half of
     * FD_SETSIZE, which leaves far more.)
     */
    private const RESERVED_FDS = 64;

    /** $_SERVER entries of the worker's command line, which no request has. */
    private const COMMAND_LINE = [
        'argv' => 0, 'argc' => 0, 'PHP_SELF' => 0, 'SCRIPT_NAME' => 0, 'SCRIPT_FILENAME' => 0,
        'PATH_TRANSLATED' => 0, 'DOCUMENT_ROOT' => 0, 'REQUEST_TIME' => 0, 'REQUEST_TIME_FLOAT' => 0,
    ];

    /**
     * The log of this process: swerve's own PSR-3 logger, in its format (the time, this worker's slot).
     *
     * It writes wherever swerve logs (the terminal, or `--log`'s file), and is a `NullLogger` with
     * `-q`. Without swerve's command line (swerve embedded), it is a `NullLogger` until
     * {@see Swerve::setLog()} is called. Give it to the libraries that take a PSR-3 logger, or
     * log directly:
     *
     * ```php
     * Swerve::log()->warning('disk almost full: {free} MB', ['free' => $free]);
     * ```
     *
     * @return LoggerInterface the log of this process
     *
     * @see Swerve::setLog  replaces it
     *
     * @topic production
     *
     * @example examples/Swerve.log/1-default.php The logger before swerve sets one
     */
    public static function log(): LoggerInterface
    {
        return self::$log ??= new NullLogger();
    }

    /**
     * Replace the logger that {@see Swerve::log()} returns.
     *
     * `bin/swerve` calls it when a worker starts. An application that embeds swerve without its
     * command line can call it to have `log()` return its own logger.
     *
     * ```php
     * Swerve::setLog(new Monolog\Logger('app'));
     * ```
     *
     * @param LoggerInterface $log returned by every later call of `Swerve::log()`
     *
     * @see Swerve::log
     *
     * @topic production
     *
     * @example examples/Swerve.setLog/1-own-logger.php Log through a PSR-3 logger of your own
     */
    public static function setLog(LoggerInterface $log): void
    {
        self::$log = $log;
    }

    /**
     * Send a message to every subscriber of `$topic`, in every worker process of this swerve, this one included.
     *
     * Returns once the message is on its way, not once delivered, and never suspends the caller,
     * even the first time this worker publishes to `$topic`: safe to call from a `finally`,
     * including one that runs after the coroutine was cancelled (a WebSocket handler's goodbye).
     * Delivery is at most once, to the subscriptions that exist when the message reaches their
     * worker: there is no history, and a worker starting later (after a reload, a recycle, a
     * crash) sees nothing sent before. The messages of one publishing worker arrive in the order
     * it published them, with no order between workers. A worker that does not read its inbox
     * loses the messages sent to it after 0.1 s. Swerve embedded without its master process
     * delivers in this process only.
     *
     * Every message travels as JSON: encoded once here, decoded once in each worker, and every
     * subscriber gets the value published, shared: a string stays a string (`'{}'` too), a list an
     * array, and an object (an array with keys) a read-only {@see Swerve\Util\SealedObject}:
     * `$message->end`.
     *
     * ```php
     * Swerve::publish('game', ['kill', $playerId]);
     * Swerve::publish('game', ['type' => 'score', 'player' => $playerId, 'points' => 10]);
     * ```
     *
     * @param string $topic   1 to 255 bytes
     * @param mixed  $message anything `json_encode()` takes except null; at most 128 KiB encoded
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes, a topic starting with "\0", or null (which a heartbeat subscription yields for "nothing came")
     * @throws \LogicException           directly in `swerve.php`, which the worker serves after: a coroutine started there waits instead
     * @throws \JsonException            for a value JSON can't express
     *
     * @see Swerve::subscribe       receives the messages
     * @see Swerve::publishRaw      bytes that are not JSON, such as a binary WebSocket frame
     * @see Swerve\OrderedChannel   when order between workers matters
     *
     * @topic coordination-across-workers
     *
     * @example examples/Swerve.publish/1-to-subscribers.php Every subscriber gets every message
     */
    public static function publish(string $topic, mixed $message): void
    {
        self::refuseInternal($topic);
        Topics::publish($topic, $message);
    }

    /**
     * Send `$bytes` unchanged to every subscriber of `$topic`, in every worker process of this swerve, this one included.
     *
     * For messages {@see Swerve::publish()} cannot carry: bytes that are not valid UTF-8, such as
     * a binary WebSocket frame, which `publish()` would fail to encode as JSON. A subscriber gets
     * exactly `$bytes`, as a plain string, never decoded. The same delivery guarantees as
     * `publish()` apply (order, at-most-once, never suspends the caller, and the rest); see its
     * docblock. A topic may mix `publish()` and `publishRaw()` messages: each subscriber gets
     * each one in its own shape.
     *
     * ```php
     * Swerve::publishRaw('frames', $binaryWebSocketFrame);
     * ```
     *
     * @param string $topic 1 to 255 bytes
     * @param string $bytes any bytes, including none; at most 128 KiB
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes, or a topic starting with "\0"
     * @throws \LogicException           directly in `swerve.php`, which the worker serves after: a coroutine started there waits instead
     *
     * @see Swerve::subscribe   receives the messages, raw or not
     * @see Swerve::publish     for a value JSON can carry
     *
     * @topic coordination-across-workers
     *
     * @example examples/Swerve.publishRaw/1-websocket-frames.php A WebSocket handler forwards binary frames to the other workers
     */
    public static function publishRaw(string $topic, string $bytes): void
    {
        self::refuseInternal($topic);
        Topics::publishRaw($topic, $bytes);
    }

    /**
     * Receive what is published to `$topic` from now on.
     *
     * The subscription exists once this returns, not from the first iteration. It ends when its
     * last reference goes, and its loop when the process drains; see {@see Subscription}. A
     * subscriber falling more than `$maxLag` seconds behind gets a {@see SubscriberLagException}
     * from the loop. With `$heartbeat`, the loop also gets `null` after that many seconds without
     * a message: for a keep-alive.
     *
     * ```php
     * foreach (Swerve::subscribe('chat') as $message) {
     *     echo $message->text, "\n";   // an object arrives as a read-only SealedObject
     * }
     * ```
     *
     * @param string     $topic     what {@see Swerve::publish()} sent to
     * @param float      $maxLag    seconds a message may wait before this subscriber reads it
     * @param float|null $heartbeat seconds without a message after which the loop yields null; null for never
     *
     * @return Subscription iterate over it to receive the messages
     *
     * @throws \InvalidArgumentException for a topic starting with "\0"
     *
     * @see Swerve::publish         sends the messages
     * @see Swerve\OrderedChannel   when every subscriber must see one common order
     *
     * @topic coordination-across-workers
     *
     * @example examples/Swerve.subscribe/1-what-arrives.php What a subscriber receives for each kind of value
     * @example examples/Swerve.subscribe/2-heartbeat.php A heartbeat for keep-alives
     */
    public static function subscribe(string $topic, float $maxLag = 30.0, ?float $heartbeat = null): Subscription
    {
        self::refuseInternal($topic);

        return new Subscription($topic, $maxLag, $heartbeat);
    }

    /** Topics starting with "\0" are swerve's own: the ordered log's channels, among others (see OrderedChannel). */
    private static function refuseInternal(string $topic): void
    {
        if (\str_starts_with($topic, "\0")) {
            throw new \InvalidArgumentException('Topics starting with "\\0" are swerve\'s own');
        }
    }

    /**
     * The cache every worker shares, held by the master process.
     *
     * A PSR-16 cache: see {@see Swerve\Cache} for how it is layered and what it keeps.
     *
     * ```php
     * $cache = Swerve::cache();
     * $user  = $cache->get("user.$id") ?? load_user($id);
     * $cache->set("user.$id", $user, 60);
     * ```
     *
     * @return \Psr\SimpleCache\CacheInterface the same cache on every call
     *
     * @see Swerve\Cache
     * @see Swerve::claim   when one worker at a time must do something
     *
     * @topic resource-management
     *
     * @example examples/Swerve.cache/1-read-through.php Load once, then read from the cache
     */
    public static function cache(): \Psr\SimpleCache\CacheInterface
    {
        return Cache::instance();
    }

    /**
     * Whether this worker drains: it is shutting down, reloading or being recycled.
     *
     * An early hint: it turns true when the drain begins, also for a recycled worker, which then
     * keeps its upgraded connections (and long SSE responses) for up to `--linger` seconds. What tells
     * that connections must close now is {@see Swerve::onShutdown()}. Long responses should end
     * soon: see docs/realtime.md. A {@see Subscription} ends its loop at that moment, not before.
     *
     * ```php
     * while (!Swerve::draining()) {
     *     $out->append(": keep-alive\n\n");
     *     phasync::sleep(15);
     * }
     * ```
     *
     * @see Swerve::onShutdown
     * @see Swerve::subscribe
     *
     * @topic production
     *
     * @example examples/Swerve.draining/1-during-a-drain.php False while serving, true once the drain begins
     */
    public static function draining(): bool
    {
        return Topics::$draining;
    }

    /**
     * Run `$callback` when this worker must now close its connections: for an upgraded connection, to say
     * goodbye in its protocol and end it.
     *
     * That is when a shutdown or a reload begins to drain the worker, and, for a worker replaced
     * by a recycle (which keeps its upgraded connections for up to `--linger` seconds), at the end
     * of that time. The callback runs in a coroutine of its own, so a slow one delays no other
     * and one that throws, which is logged, stops none; it runs at once when that moment has
     * passed already. Its waits get the worker's {@see WorkerStoppingException} too: write a
     * goodbye inside `phasync::shielded()`.
     *
     * The callback belongs to the `Scope` that registered it (a request's, or outside any request
     * the root scope), runs in it, and goes when that request ends, without running: swerve holds
     * no reference to the request. phasync
     * collects the cycles half a second after a coroutine ends, so what the callback holds is
     * freed shortly after.
     *
     * ```php
     * YourProtocol::from($request, function (YourProtocol $conn) {
     *     Swerve::onShutdown(fn () => phasync::shielded(fn () => $conn->end('restarting')));
     *     foreach ($conn as $message) {
     *         $conn->send(handle($message));
     *     }
     * });
     * ```
     *
     * @param \Closure $callback called without arguments; its return value is ignored
     *
     * @throws \LogicException outside a coroutine
     *
     * @see Swerve::awaitShutdown    to wait for it in sequential code
     * @see Swerve::draining
     *
     * @topic production
     *
     * @example examples/Swerve.onShutdown/1-websocket-goodbye.php Close a WebSocket with a reason when the server stops
     */
    public static function onShutdown(\Closure $callback): void
    {
        Shutdown::listen($callback);
    }

    /**
     * Wait until this worker must close its connections, see {@see Swerve::onShutdown()}.
     *
     * ```php
     * if (Swerve::awaitShutdown(30)) {
     *     $ws->end(1001);
     * }
     * ```
     *
     * @param float|null $timeout seconds to wait at most; null for as long as it takes
     *
     * @return bool true once it must (at once if it must already), false when `$timeout` passed first
     *
     * @throws \LogicException outside a coroutine
     *
     * @topic production
     *
     * @example examples/Swerve.awaitShutdown/1-timeout.php Wait with a timeout
     */
    public static function awaitShutdown(?float $timeout = null): bool
    {
        return Shutdown::await($timeout);
    }

    /**
     * php.ini settings a package needs in every swerve process. Call it from a file listed in
     * your package's composer.json `files`: Composer loads it after swerve's own, as the master
     * starts. swerve applies the settings with its startup restart, over its own settings; an
     * explicit `-d` on the command line still wins.
     *
     * Calls after startup change nothing, so the file may run again: an application that
     * includes Composer's autoloader in every request includes it again.
     *
     * ```php
     * Swerve::ini(['memory_limit' => '512M']);
     * ```
     *
     * @param array<string, string|int|float|bool> $settings
     *
     * @topic production
     */
    public static function ini(array $settings): void
    {
        if (self::$started) {
            return;
        }
        foreach ($settings as $name => $value) {
            self::$ini[$name] = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }
    }

    /**
     * Run $callback in every worker as it starts, before the application loads, inside the
     * worker's event loop, so it may start coroutines that run for the worker's life. Register it
     * the way {@see Swerve::ini()} says; calls after startup change nothing. A callback that
     * throws stops the worker, as an application that fails to load does.
     *
     * ```php
     * // a file in your package's composer.json "files"
     * Swerve::onWorkerStart(function () {
     *     phasync::go(function () {
     *         while (true) {
     *             refresh_feature_flags();
     *             phasync::sleep(60);
     *         }
     *     });
     * });
     * ```
     *
     * @param \Closure():void $callback
     *
     * @topic production
     */
    public static function onWorkerStart(\Closure $callback): void
    {
        if (self::$started) {
            return;
        }
        self::$workerStart[] = $callback;
    }

    /**
     * @internal the settings ini() registered, for swerve's startup restart
     *
     * @return array<string, string>
     */
    public static function registeredIni(): array
    {
        return self::$ini;
    }

    /**
     * @internal end startup in this worker, and run the onWorkerStart() callbacks
     */
    public static function startWorker(): void
    {
        self::$started = true;
        foreach (self::$workerStart as $callback) {
            $callback();
        }
    }

    /**
     * @internal the HTTP limits and the access log, set once by `inc/lifecycle.php` from the
     *           command line, before the application runs
     */
    public static function configureHttp(int $maxBody, ?TrustedProxies $proxies, LoggerInterface $logger): void
    {
        self::$maxBody        = $maxBody;
        self::$trustedProxies = $proxies;
        self::$accessLog      = $logger instanceof Logger && $logger->access ? $logger : null;
        self::$maxConnections = self::connectionLimit();
    }

    /**
     * A worker serves at most so many connections at once that its file descriptors stay below
     * the open-file limit; more wait, see reclaim(). Also lowers the limit to FD_SETSIZE
     * without the extension (see below), and arms $exiting for a clean shutdown.
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
    private static function connectionLimit(): int
    {
        \register_shutdown_function(static function () { HttpConnection::$exiting = true; });
        $rlimit = \posix_getrlimit();
        $limit  = (int) $rlimit['soft openfiles'];
        if (!\extension_loaded('phasync') && $limit > \PHP_FD_SETSIZE) {
            $hard = 'unlimited' === $rlimit['hard openfiles'] ? \POSIX_RLIM_INFINITY : (int) $rlimit['hard openfiles'];
            if (!\posix_setrlimit(\POSIX_RLIMIT_NOFILE, \PHP_FD_SETSIZE, $hard)) {
                throw new \RuntimeException('Could not lower the open-file limit to ' . \PHP_FD_SETSIZE);
            }
            $limit        = \PHP_FD_SETSIZE;
            self::$remedy = 'add workers, or install the phasync extension: without it a worker serves no more, whatever the open-file limit';
        }
        $max = $limit - self::RESERVED_FDS;
        if (!\extension_loaded('phasync')) {
            $max = \min($max, \intdiv(\PHP_FD_SETSIZE, 2));
        }
        self::log()->info('A worker serves at most {max} connections at once', ['max' => $max]);

        return $max;
    }

    /**
     * Close a connection that waits on its client, as nginx reuses its idle keep-alive
     * connections when it runs short. Else a client holding the worker's connections, which
     * costs it almost nothing, would lock everyone else out. The oldest first of: those already
     * answered (lingering before the close, or skipping an unread body), then those that never
     * completed a request (a new connection that sent nothing yet, a slow head), then the
     * kept-alive ones, and last those waiting for more of a request body. A connection running
     * its handler, or upgraded (101), is never closed to make room: see HttpConnection::$reclaimable.
     */
    private static function reclaim(): bool
    {
        foreach ([HttpConnection::ANSWERED, HttpConnection::NEW, HttpConnection::KEPT_ALIVE, HttpConnection::BODY] as $state) {
            foreach (self::$connections as $connection) {
                if ($connection->reclaim($state)) {
                    ++self::$reclaimed;
                    self::logShort();

                    return true;
                }
            }
        }

        return false;
    }

    /** A warning that the worker runs short, at most once a minute: under a flood, every accept would log one. */
    private static function logShort(): void
    {
        $now = \microtime(true);
        if ($now - self::$shortLogged >= 60) {
            self::$shortLogged = $now;
            self::log()->warning('At the limit of {max} connections; {n} waiting on their clients closed to make room since the last warning; {remedy}', ['max' => self::$maxConnections, 'n' => self::$reclaimed, 'remedy' => self::$remedy]);
            self::$reclaimed = 0;
        }
    }

    /**
     * The next HTTP request this worker should serve: a connection `phasync\ext\worker_next()`
     * (or, without the extension, phasync/hooked's replacement) hands this lifecycle, parsed,
     * with its handler already running as a fiber inside `sapi()`. Call it in a loop; it never
     * returns null for an HTTP worker, only throws.
     *
     * `$handler` is one of three forms: a script's path (`require`d per request, reading
     * `php://input` and the superglobals, as PHP-FPM would); a closure with no required
     * parameters (the same, inline); or `function (ServerRequestInterface $request):
     * ResponseInterface` (PSR-7). `$_SERVER` for the request starts from what the application
     * left there while booting (its `.env` values, a framework's flags; never the command
     * line's own entries), plus this request's own (`REQUEST_METHOD`, `HTTP_*`, ...); it throws
     * once, the first time, if that boot `$_SERVER` holds an object (sharing it across every
     * request would be a bug the application should see at once).
     *
     * A static file under `$webRoot`, or a redirect to add a directory's trailing slash, never
     * reaches `$handler`: see {@see Router}. `$webRoot` null serves no static files; everything
     * goes to `$handler`.
     *
     * A kept-alive connection either stays here directly for its next request (when this
     * worker has nothing better to do: another call to accept() is already waiting) or is
     * handed back to the lot (`WorkerDuplex::park()`) for whichever worker is idle when its
     * next request arrives; an app-set `Connection: close` always ends it. A connection with
     * bytes already read but not yet parsed (pipelined requests) is never parked: it keeps
     * being read here until its buffer is empty.
     *
     * The first call starts a background coroutine that keeps pulling connections with
     * `worker_next()`, independently of any one accept() call: a request from any connection
     * this worker holds may be the one a given accept() call returns, not necessarily from the
     * connection it would have pulled itself.
     *
     * @param string|\Closure(ServerRequestInterface): \Psr\Http\Message\ResponseInterface $handler
     *
     * @throws \LogicException called from inside the request it is already serving (it is the
     *                          worker script's own loop, not something a request's handler calls),
     *                          or with `--no-http` (no listener to ask for a connection)
     * @throws \Throwable      whatever the ext (or phasync/hooked without it) throws when this
     *                         process is not a worker, or already has an outstanding call
     *
     * @topic request-handlers
     */
    public static function accept(?string $webRoot, string|\Closure $handler): \Fiber
    {
        $fiber = \Fiber::getCurrent();
        if (null !== $fiber && isset((self::$requestFibers ??= new \WeakMap())[$fiber])) {
            throw new \LogicException('Swerve::accept() cannot be called from inside the request it is serving: call it only from the worker script\'s own loop');
        }
        if (self::$queue) {
            return \array_shift(self::$queue);
        }
        if (!self::$readySent) {
            self::$readySent = true;
            Worker::current()->ready();
        }
        if (!self::$acceptorStarted) {
            self::$acceptorStarted = true;
            // The first worker_next() runs here, in the caller's own fiber, so --no-http (and
            // anything else the ext throws for a process that cannot accept) reaches it
            // directly, as documented; every one after is this background coroutine's own,
            // decoupled from any one accept() call: a connection that fails before its first
            // request parses (a malformed start, an idle one that times out) never pushes a
            // fiber, and must not be what an accept() call is left waiting on.
            self::serveConnection(\phasync\ext\worker_next(), $webRoot, $handler);
            phasync::go(static function () use ($webRoot, $handler): void {
                while (true) {
                    self::serveConnection(\phasync\ext\worker_next(), $webRoot, $handler);
                }
            });
        }
        ++self::$idle;
        try {
            while (!self::$queue) {
                phasync::awaitFlag(self::$queueFlag ??= new \stdClass());
            }
        } finally {
            --self::$idle;
        }

        return \array_shift(self::$queue);
    }

    /**
     * Wraps a connection worker_next() handed out in an {@see \Swerve\Http\HttpConnection}: every
     * request it parses becomes a fiber pushed to the queue accept() reads, which this
     * connection's own coroutine waits for before looking at the next request (keep-alive) or
     * ending (see onIdle, in accept()'s docblock, for what happens between requests).
     *
     * @param resource $conn
     */
    private static function serveConnection($conn, ?string $webRoot, string|\Closure $handler): void
    {
        $io = new WorkerDuplex($conn);
        $onIdle = static function (HttpConnection $connection) use ($io): bool {
            if (self::$idle > 0 || !$connection->bufferEmpty()) {
                return false; // this worker is idle anyway, or parking would lose buffered bytes
            }
            $io->park();

            return true;
        };
        $worker     = Worker::current();
        $dispatch   = static function (ClientRequest $request) use ($webRoot, $handler, $worker): void {
            [$kind, $resolved] = self::resolveHandler($handler);
            $requestFiber       = phasync::go(static function () use ($request, $webRoot, $kind, $resolved, $worker): void {
                self::$requestFibers[\Fiber::getCurrent()] = true;
                $id = $worker->requestStarted(static fn () => $request->getMethod() . ' ' . $request->getTarget());
                try {
                    self::dispatch($request, $webRoot, $kind, $resolved);
                } finally {
                    $worker->requestDone($id);
                }
            });
            self::$queue[] = $requestFiber;
            phasync::raiseFlag(self::$queueFlag ??= new \stdClass());
            phasync::await($requestFiber);
        };
        $connection = new HttpConnection($io, $dispatch, self::log(), self::$accessLog, self::$maxBody, self::$trustedProxies, $onIdle);
        $id         = \spl_object_id($connection);
        self::$connections[$id] = $connection;
        phasync::go(static function () use ($connection, $id): void {
            try {
                $connection->serve();
            } finally {
                unset(self::$connections[$id]);
            }
        });
        // At the limit, nothing may be reclaimable yet (a connection just accepted, including
        // this one, has not had a chance to reach a waiting state): yielding, instead of moving
        // on to accept more, is what lets them get there (this one's own coroutine, started
        // above, included).
        while (\count(self::$connections) > self::$maxConnections && !self::reclaim()) {
            phasync::sleep();
        }
    }

    /** Where the route for $request's target leads: a static file, a redirect, or $kind/$resolved (see accept()). */
    private static function dispatch(ClientRequest $request, ?string $webRoot, string $kind, string|\Closure $resolved): void
    {
        $route = (new Router($webRoot))->route($request->getTarget());
        switch ($route[0]) {
            case Router::STATIC:
                StaticFile::send($request, $route[1]);

                return;
            case Router::REDIRECT:
                $request->sendResponseHeaders(301, ['Location' => $route[1], 'Content-Length' => '0']);
                $request->end();

                return;
            case Router::BAD:
                $request->sendResponseHeaders(400, ['Content-Type' => 'text/plain', 'Content-Length' => '12']);
                $request->write("Bad Request\n");
                $request->end();

                return;
            default: // Router::APP
                // $route[1] (an existing PHP file Router matched) is a path to require, same as
                // $resolved only when $resolved is one too ('path': -S's router, or a bare
                // `swerve some-app.php`); a 'script'/'psr7' $resolved is the application's own
                // closure regardless of what file the target happened to match.
                self::run($request, $kind, 'path' === $kind && isset($route[1]) ? $route[1] : $resolved, $webRoot);
        }
    }

    /** Builds the request's $_SERVER (and $_GET, $_COOKIE, ...), then runs $resolved inside sapi(). */
    private static function run(ClientRequest $request, string $kind, string|\Closure $resolved, ?string $webRoot = null): void
    {
        $target  = $request->getTarget();
        $q       = \strpos($target, '?');
        $query   = false === $q ? '' : \substr($target, $q + 1);
        $headers = $request->getRequestHeaders();
        $now     = \microtime(true);
        $server  = self::bootServer();
        foreach ($headers as $name => $values) {
            $server['HTTP_' . \strtoupper(\strtr($name, '-', '_'))] = \implode('cookie' === $name ? '; ' : ', ', $values);
        }
        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'][0];
        }
        if (isset($headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $headers['content-length'][0];
        }
        [$remoteAddr, $remotePort] = self::address($request->peer());
        [$localAddr, $localPort]   = self::address($request->local());
        $server = \array_replace($server, [
            'SERVER_SOFTWARE'    => 'Swerve',
            'GATEWAY_INTERFACE'  => 'CGI/1.1',
            'SERVER_PROTOCOL'    => 'HTTP/' . $request->getProtocolVersion(),
            'SERVER_NAME'        => \preg_replace('/:\d+$/', '', $headers['host'][0] ?? $localAddr),
            'SERVER_ADDR'        => $localAddr,
            'SERVER_PORT'        => $localPort,
            'REMOTE_ADDR'        => $remoteAddr,
            'REMOTE_PORT'        => $remotePort,
            'REQUEST_SCHEME'     => $request->getScheme(),
            'REQUEST_METHOD'     => $request->getMethod(),
            'REQUEST_URI'        => $target,
            'DOCUMENT_URI'       => false === $q ? $target : \substr($target, 0, $q),
            'QUERY_STRING'       => $query,
            'REQUEST_TIME_FLOAT' => $now,
            'REQUEST_TIME'       => (int) $now,
        ]);
        if ('https' === $server['REQUEST_SCHEME']) {
            $server['HTTPS'] = 'on';
        } else {
            unset($server['HTTPS']);
        }
        if ('path' === $kind) {
            // What mod_php/php-fpm give the script that is about to run: the file under the
            // public root that matched (Router::APP's own file), or the fallback script when
            // none did -- either way $resolved, by the time dispatch() calls here. No PATH_INFO
            // (swerve, like -S, routes by file match, not by a prefix of the path).
            \assert(\is_string($resolved));
            $scriptName = null !== $webRoot && \str_starts_with($resolved, "$webRoot/")
                ? \substr($resolved, \strlen($webRoot))
                : '/' . \basename($resolved);
            $server['SCRIPT_FILENAME']  = $resolved;
            $server['SCRIPT_NAME']      = $scriptName;
            $server['PHP_SELF']         = $scriptName;
            $server['PATH_TRANSLATED']  = $resolved;
            $server['DOCUMENT_ROOT']    = $webRoot ?? \dirname($resolved);
        }
        $_SERVER  = $server;
        \parse_str($query, $_GET);
        $_COOKIE  = isset($headers['cookie']) ? ServerRequest::cookies(\implode('; ', $headers['cookie'])) : [];
        $_POST    = [];
        $_FILES   = [];
        $_REQUEST = $_GET;
        // $_SERVER is a plain global the ext shares across every request (unlike $_GET/$_POST/...,
        // which are Scope state): on a bailout (a script's wp_redirect()+exit, for one -- any
        // normal use, not a bug), $_SERVER no longer holds this request's values by the time PHP's
        // registered shutdown functions run. Registered before the handler gets a chance to
        // register its own (register_shutdown_function() runs them in registration order), this
        // restores it first, so whatever a script's own shutdown callback reads (WordPress's
        // _wp_cron(), hooked to 'shutdown') is still this request's $_SERVER, not whatever it was
        // reset to.
        \register_shutdown_function(static function () use ($server): void {
            $_SERVER = $server;
        });
        if ('psr7' === $kind) {
            (new Sapi($request, false))->run(static fn () => Bridge::serve($request, $resolved));

            return;
        }
        $ext = \function_exists('phasync\ext\sapi');
        (new Sapi($request, true))->run(static function () use ($kind, $resolved, $headers, $ext): void {
            if (\preg_match('#^(?:application/x-www-form-urlencoded|multipart/form-data)\b#i', $headers['content-type'][0] ?? '')) {
                [$_POST, $_FILES] = $ext ? \request_parse_body() : \phasync\hooked\request_parse_body();
                $_REQUEST         = \array_merge($_GET, $_POST);
            }
            if ('path' !== $kind) {
                $resolved();

                return;
            }
            // require would run $resolved as part of *this* closure's own scope: a top-level
            // variable the script sets becomes local to this call, invisible to a function of its
            // own that reads it with `global` (WordPress does, repeatedly) -- same as any other
            // SAPI, the script needs to run as if it were the actual entry point, not an include.
            $ext ? \phasync\ext\include_global($resolved) : require $resolved;
        });
    }

    /** @return array{string, string} an address "host:port" or "[v6]:port" as its host and port */
    private static function address(string $address): array
    {
        return \preg_match('/^\[?(.+?)\]?:(\d+)$/', $address, $m) ? [$m[1], $m[2]] : [$address, ''];
    }

    /** $_SERVER as the application left it after booting, once: see configureHttp(). */
    private static function bootServer(): array
    {
        if (null === self::$bootServer) {
            $server = \array_diff_key($_SERVER, self::COMMAND_LINE);
            foreach ($server as $key => $value) {
                if (\is_object($value)) {
                    throw new \LogicException("\$_SERVER['$key'] holds an object; accept() refuses to carry it into every request's \$_SERVER");
                }
            }
            self::$bootServer = $server;
        }

        return self::$bootServer;
    }

    /**
     * `'path'` for a script (its path, $resolved unchanged), `'script'` for a `function (): void`
     * closure, `'psr7'` for `function (ServerRequestInterface $request): ResponseInterface`.
     *
     * @return array{string, string|\Closure}
     */
    private static function resolveHandler(string|\Closure $handler): array
    {
        if (\is_string($handler)) {
            return ['path', $handler];
        }
        self::$handlerKinds ??= new \WeakMap();
        if (isset(self::$handlerKinds[$handler])) {
            return [self::$handlerKinds[$handler], $handler];
        }
        $f      = new \ReflectionFunction($handler);
        $params = $f->getParameters();
        if (0 === $f->getNumberOfRequiredParameters()) {
            $kind = 'script';
        } else {
            $type = 1 === \count($params) ? $params[0]->getType() : null;
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !\is_a($type->getName(), ServerRequestInterface::class, true)) {
                throw new \LogicException('Swerve::accept() runs a script path, function (): void, or function (ServerRequestInterface $request): ResponseInterface, not ' . self::describeClosure($f));
            }
            $kind = 'psr7';
        }
        self::$handlerKinds[$handler] = $kind;

        return [$kind, $handler];
    }

    private static function describeClosure(\ReflectionFunction $f): string
    {
        return 'the closure function (' . \implode(', ', \array_map(static fn (\ReflectionParameter $p) => \trim(($p->getType() ?? '') . ' $' . $p->getName()), $f->getParameters())) . ')'
            . ($f->hasReturnType() ? ': ' . $f->getReturnType() : '')
            . ' (' . $f->getFileName() . ':' . $f->getStartLine() . ')';
    }

    /**
     * The installed version, as Composer knows it: `0.1.0`, or `dev-main` in a checkout.
     *
     * @return string the version, or 'unknown' when Composer has none for `phasync/swerve`
     *
     * @topic production
     *
     * @example examples/Swerve.getVersion/1-version.php The installed version
     */
    public static function getVersion(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('phasync/swerve') ?? 'unknown';
    }
}
