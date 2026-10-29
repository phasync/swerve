<?php

namespace Swerve;

use phasync;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Serializor\Codec;
use Swerve\Http\ErrorPage;
use Swerve\Http\HttpClientRequest;
use Swerve\Http\HttpConnection;
use Swerve\Http\SapiRequest;
use Swerve\Lot\LotClient;
use Swerve\Psr\Psr7;
use Swerve\Util\Logger;
use Swerve\Util\Shutdown;
use Swerve\Util\Topics;

/**
 * What swerve gives the application: its log, the cache and the messages shared by every worker, and the state of the worker.
 *
 * Every method is static. The cache is APCu memory that every worker shares; messages go through
 * the master process. A coroutine that `swerve.php` starts waits for the worker to serve before
 * its first publish goes through; directly in `swerve.php` publishing throws (see docs/bootstrap.md).
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
    /** accept() was called in this lifecycle (its shutdown function is registered). */
    private static bool $accepting = false;

    /**
     * The worker's own $_SERVER, which each request's starts from (a request's Scope starts empty),
     * without the worker's argv and argc: PHP's request startup sets the request's.
     */
    private static array $server;

    private function __construct()
    {
    }

    /** The log of this process, see log(). */
    private static ?LoggerInterface $log = null;

    /** @var array<string, StaticFiles> accept()'s static files, by the directory it was given */
    private static array $staticFiles = [];

    /**
     * The log of this process: swerve's own PSR-3 logger, in its format (the time, the process).
     *
     * It writes to standard error, which in swerve's processes is the terminal or `--log`'s file.
     * Give it to the libraries that take a PSR-3 logger, or log directly:
     *
     * ```php
     * Swerve::log()->warning('disk almost full: {free} MB', ['free' => $free]);
     * ```
     *
     * @topic production
     */
    public static function log(): LoggerInterface
    {
        return self::$log ??= new Logger(\STDERR, (string) \getmypid(), LogLevel::NOTICE);
    }

    /**
     * Send a message to every subscriber of `$topic`, in every worker process of this swerve, this one included.
     *
     * Returns once the message is in the log every worker shares (APCu), and never suspends the
     * caller: safe to call from a `finally`, including one that runs after the coroutine was
     * cancelled (a WebSocket handler's goodbye), and directly in `swerve.php`. Every message of
     * every publisher has its place in one order, and every subscriber, in every worker, receives
     * them in that order. A subscription receives what is published after it was made: there is
     * no history. Delivery is at most once, and a loss is never silent: a worker that falls a
     * whole `--pubsub-ring` behind loses messages, and its subscribers get a
     * {@see SubscriberLagException} where they were (a warning is logged at 3/4 of the ring).
     * Outside a swerve worker, messages stay in this process.
     *
     * A message travels serialized (`serialize()`): any bytes, arrays, enums, and objects of
     * stdClass. A subscriber gets the value published: scalars and arrays unserialized once per
     * worker, and a value holding objects as a copy of its own for each subscriber, so none sees
     * another's changes.
     *
     * ```php
     * Swerve::publish('game', ['kill', $playerId]);
     * Swerve::publish('frames', $binaryWebSocketFrame);   // bytes stay bytes
     * ```
     *
     * @param string $topic   1 to 255 bytes
     * @param mixed  $message a string, number, bool, array, enum or stdClass, not null; at most 128 KiB serialized
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes, a topic starting with "\0", null (which a heartbeat subscription yields for "nothing came"), a value that can't be serialized (a closure), or an object of another class than stdClass
     *
     * @see Swerve::subscribe       receives the messages
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
     * Receive what is published to `$topic` from now on.
     *
     * The subscription exists once this returns, not from the first iteration. It ends when its
     * last reference goes, and its loop when the process drains; see {@see Subscription}. A
     * subscriber falling more than `$maxLag` seconds behind gets a {@see SubscriberLagException}
     * from the loop, and so does one whose worker lost messages. With `$heartbeat`, the loop
     * also gets `null` after that many seconds without a message: for a keep-alive.
     *
     * ```php
     * foreach (Swerve::subscribe('chat') as $message) {
     *     echo $message["text"], "\n";   // the value published
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

    /** Topics starting with "\0" are swerve's own. */
    private static function refuseInternal(string $topic): void
    {
        if (\str_starts_with($topic, "\0")) {
            throw new \InvalidArgumentException('Topics starting with "\\0" are swerve\'s own');
        }
    }

    /**
     * The cache every worker shares, held in APCu, the shared memory every worker inherits from
     * the master they are forked from.
     *
     * A PSR-16 cache: see {@see Swerve\Cache} for what it keeps and how it evicts.
     *
     * ```php
     * $cache = Swerve::cache();
     * $user  = $cache->get("user.$id") ?? load_user($id);
     * $cache->set("user.$id", $user, 60);
     * ```
     *
     * @return \Psr\SimpleCache\CacheInterface the same cache on every call
     *
     * @throws \LogicException without APCu enabled for the CLI (swerve's master arranges this itself)
     *
     * @see Swerve\Cache
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
     * The container that limits how many instances of a service exist across every worker: a
     * PSR-11 container for frameworks, beside their own, for the resources a server shares (the
     * database's connections). See {@see Container}.
     *
     * ```php
     * Swerve::container()->add(\PDO::class, fn () => new \PDO($dsn, $user, $password), limit: 151);
     * $db = Swerve::container()->get(\PDO::class);   // this request's, waiting while 151 are out
     * ```
     *
     * @return Container the same container on every call
     *
     * @topic resource-management
     */
    public static function container(): Container
    {
        return Container::instance();
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
     * Serve the next request: wait until the master hands this worker a connection with a
     * request waiting, and return the coroutine that serves it. The coroutine ends once the
     * response is sent; the connection then goes back to the master, which hands its next
     * request to whichever worker asks first.
     *
     * The application's swerve.php decides how many requests a worker serves at once:
     *
     * ```php
     * // one request per run of swerve.php, which starts over clean for the next (as under php-fpm)
     * phasync::await(Swerve::accept('wordpress/', 'wordpress/index.php'));
     *
     * // every request as it comes, concurrently
     * while (Swerve::accept('public/', $app->handle(...))) {
     * }
     *
     * // one at a time, keeping the application between requests
     * while ($request = Swerve::accept('public/', $app->handle(...))) {
     *     phasync::await($request);
     * }
     * ```
     *
     * Requests for a file under $staticPath are answered without the application, and don't use
     * up the call: it goes on with the connection's next request as long as the client has
     * written it, and with another connection when this one goes idle after files only. The
     * first other request goes to $handler.
     *
     * @param ?string              $staticPath the directory of static files (as {@see StaticFiles}); null: none
     * @param string|\Closure|null $handler    a script's path: run as a fresh main script, its
     *                                         variables globals, echo and header() the response,
     *                                         for every request that names no PHP file under its
     *                                         directory; null: such a request gets 404, and
     *                                         $staticPath's PHP files still run (`swerve -S` on a
     *                                         directory without index.php); a closure without
     *                                         parameters: likewise; a closure taking a
     *                                         ServerRequestInterface: returns a ResponseInterface,
     *                                         also under sapi(), its request made from the
     *                                         superglobals and php://input as under php-fpm
     *
     * @return ?\Fiber the coroutine serving the request; null once the worker stops
     *
     * @throws \LogicException when called inside a request
     */
    public static function accept(?string $staticPath, string|\Closure|null $handler): Accepted
    {
        if ('swerve' === \php_sapi_name()) {
            throw new \LogicException('Swerve::accept() is called by the worker\'s swerve.php, not inside a request');
        }
        if (!self::$accepting) {
            self::$accepting = true;
            self::$server    = \array_diff_key($_SERVER, ['argv' => 0, 'argc' => 0]);
            \register_shutdown_function(static function () { HttpConnection::$exiting = true; });
        }

        $files = null === $staticPath ? null : self::$staticFiles[$staticPath] ??= new StaticFiles($staticPath);

        return LotClient::get()->accept(self::handler($handler, $files?->root), $files);
    }

    /**
     * Take the connection off the request being served, for $upgrade: a WebSocket, a stream of
     * server-sent events, or any response written to the ClientRequest. The type of $upgrade's
     * parameter says which. The request goes on to its end, and $upgrade runs on the connection
     * once it ended, as long as it likes, while the worker serves other requests.
     *
     * From the call on, the request has no connection: connection_aborted() is true, header()
     * has no effect, and output throws.
     *
     * A swerve.php that has called accept() only once may end with this request (WordPress's
     * serves one request per lifecycle), and its connections with it. A WebSocket opened there
     * moves on to one of swerve's WebSocket workers, started as they are needed: its `101` is sent
     * here, and the closure, serialized with phasync/serializor along with what it captures, runs
     * in that process. It has the application's Composer autoload (vendor/autoload.php: its
     * classes, and the files listed in composer.json's `autoload.files`) and nothing else of the
     * application: a class or function the closure needs must come from there. One that doesn't
     * is fatal, a deployment broken: a critical error in the log says what was missing, and the
     * WebSocket worker exits with its sockets (the next WebSocket that moves starts another).
     *
     * Either way the closure runs in a Scope of its own, seeded with the request's state as it was
     * at this call: $_GET, $_POST, $_COOKIE, $_FILES, $_REQUEST, $_SERVER, $_SESSION (the data; the
     * session itself was written and closed with the request), the default timezone and the intl
     * locale. setlocale() is process-wide, not Scope state.
     *
     * ```php
     * while (Swerve::accept(null, function () {
     *     if ('/chat' === $_SERVER['REQUEST_URI']) {
     *         Swerve::upgrade(static function (Swerve\WebSocket $ws): void {
     *             foreach ($ws as $message) {
     *                 $ws->send($message);
     *             }
     *         });
     *
     *         return;
     *     }
     *     echo "Hello, World\n";
     * })) {
     * }
     * ```
     *
     * @param \Closure(WebSocket|ServerSentEvents|ClientRequest): void $upgrade a static closure: it
     *                                                                may run in another worker, with copies of what it captures
     *
     * @throws \TypeError        for a closure that is not static, or takes something else
     * @throws UpgradeException  when the request can't be upgraded so: a WebSocket closure for a
     *                           request that is no valid WebSocket handshake
     * @throws \LogicException   outside a request, a second time, once output began (also into
     *                           an output buffer), or for a closure that has to move and can't
     *                           be serialized with what it captures
     */
    public static function upgrade(\Closure $upgrade): void
    {
        $function = new \ReflectionFunction($upgrade);
        if (null !== $function->getClosureThis()) {
            throw new \TypeError('Swerve::upgrade() takes a static closure: it may run in another worker');
        }
        $type = $function->getParameters()[0] ?? null;
        $type = $type?->getType();
        if (!$type instanceof \ReflectionNamedType || !\in_array($type->getName(), [WebSocket::class, ServerSentEvents::class, ClientRequest::class], true)) {
            throw new \TypeError('Swerve::upgrade() takes a closure whose parameter is a ' . WebSocket::class . ', a ' . ServerSentEvents::class . ' or a ' . ClientRequest::class);
        }
        $client = SapiRequest::client();
        if (WebSocket::class === $type->getName()) {
            $handshake = WebSocket::handshake($client->getMethod(), $client->getProtocolVersion(), $client->getRequestHeaders(), !$client->eof());
            if (!$handshake->accepted()) {
                throw new UpgradeException("Not a WebSocket handshake: $handshake->body", $handshake->status, $handshake->headers, $handshake->body);
            }
            if (LotClient::get()->shortLived()) {
                // It moves to a WebSocket worker as the request ends, with the request's state:
                // serialized now, so a closure that can't go fails here
                $moving = [$upgrade, SapiRequest::state(), $handshake->headers['sec-websocket-extensions'] ?? null];
                try {
                    $closure = (new Codec())->serialize($moving);
                } catch (\Throwable $e) {
                    throw new \LogicException('Swerve::upgrade(): the closure moves to the WebSocket worker, and what it captures can\'t be serialized: ' . $e->getMessage(), 0, $e);
                }
                if (\strlen($closure) > LotClient::MAX_MOVE) {
                    throw new \LogicException('Swerve::upgrade(): the closure moves to the WebSocket worker, and serialized with what it captures it is ' . \strlen($closure) . ' bytes, more than ' . LotClient::MAX_MOVE);
                }
                $upgrade = static fn (ClientRequest $client) => self::moveWebSocket($client, $closure);
            }
        }
        SapiRequest::upgrade($upgrade, SapiRequest::state());
    }

    /**
     * @param string|null $root the static directory, real path: a script handler runs the PHP
     *                          files below it that a request names, as php-fpm behind nginx does
     *
     * @return \Closure(ClientRequest): void
     */
    private static function handler(string|\Closure|null $handler, ?string $root): \Closure
    {
        if (!$handler instanceof \Closure) {
            $fallback = null;
            if (null !== $handler) {
                $script = \realpath($handler);
                if (false === $script || !\is_file($script)) {
                    throw new \ValueError("Swerve::accept(): the script $handler does not exist");
                }
                $root     ??= \dirname($script);
                $fallback   = [$script, \str_starts_with($script, "$root/") ? \substr($script, \strlen($root)) : '/' . \basename($script), null];
            } elseif (null === $root) {
                throw new \ValueError('Swerve::accept(): without a handler, $staticPath names the directory to serve');
            }

            return self::sapi($root, static fn (ClientRequest $client) => self::route($client, $root) ?? $fallback, static function (ClientRequest $client, string $file): void {
                \chdir(\dirname($file));
                \phasync\ext\include_global($file);
            });
        }
        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $route  = static fn () => [$script, '/' . \basename($script), null];
        $params = (new \ReflectionFunction($handler))->getParameters();
        if ([] === $params) {
            return self::sapi(\dirname($script), $route, static fn () => $handler());
        }
        $type = $params[0]->getType();
        if ($type instanceof \ReflectionNamedType && \is_a($type->getName(), ServerRequestInterface::class, true)) {
            return self::sapi(\dirname($script), $route, static fn (ClientRequest $client) => Psr7::serve($client, $handler));
        }
        throw new \TypeError('Swerve::accept(): a handler closure takes no parameters, or a ' . ServerRequestInterface::class . ' first');
    }

    /**
     * The PHP file below $root that the request names, as [file, SCRIPT_NAME, PATH_INFO]:
     * `/a/b.php/more` runs a/b.php, `/a/` runs a/index.php; null for any other request. Never a
     * path with a dot-segment (`/.git/x.php`), nor one that leaves $root.
     *
     * @return array{0: string, 1: string, 2: ?string}|null
     */
    private static function route(ClientRequest $client, string $root): ?array
    {
        $path = \rawurldecode((string) \parse_url($client->getTarget(), \PHP_URL_PATH));
        if (\str_contains($path, '/.') || \str_contains($path, "\0")) {
            return null;
        }
        if (\preg_match('#^(/.*?\.php)(/.*)?$#i', $path, $m)) {
            [$name, $info] = [$m[1], $m[2] ?? null];
        } elseif (\str_ends_with($path, '/')) {
            [$name, $info] = [$path . 'index.php', null];
        } else {
            return null;
        }
        $file = \realpath($root . $name);

        return false !== $file && \str_starts_with($file, "$root/") && \is_file($file) ? [$file, $name, $info] : null;
    }

    /**
     * The request ended with its connection taken off it: what accept() returned is ready, and
     * $upgrade gets the connection, in this coroutine.
     */
    private static function upgraded(ClientRequest $client, \Closure $upgrade): void
    {
        if ($client instanceof HttpClientRequest) {
            $client->handedOff();
        }
        match ((new \ReflectionFunction($upgrade))->getParameters()[0]->getType()->getName()) {
            WebSocket::class        => WebSocket::from($client, $upgrade),
            ServerSentEvents::class => $upgrade(new ServerSentEvents($client)),
            default                 => $upgrade($client),
        };
    }

    /** Answer the handshake here, and move the connection and $closure (serialized) to the WebSocket worker. */
    private static function moveWebSocket(HttpClientRequest $client, string $closure): void
    {
        if (null !== WebSocket::answer($client)) {
            LotClient::get()->moveWebSocket($client, $closure);
        }
    }

    /**
     * Serve with $code under sapi(), whose request startup gives it the superglobals PHP gives a
     * script, $_SERVER for the script $route names.
     *
     * @param \Closure(ClientRequest): array{0: string, 1: string, 2: ?string} $route the script: [file, SCRIPT_NAME, PATH_INFO]
     * @param \Closure(ClientRequest, string): void                         $code  given the script file
     *
     * @return \Closure(ClientRequest): void
     */
    private static function sapi(string $root, \Closure $route, \Closure $code): \Closure
    {
        return static function (ClientRequest $client) use ($root, $route, $code): void {
            $routed = $route($client);
            if (null === $routed) {
                ErrorPage::send($client, 404);

                return;
            }
            [$file, $name, $info] = $routed;
            $server               = self::serverVariables($client, $root, $file, $name, $info);
            $upgrade              = SapiRequest::run($client, $server, static fn () => $code($client, $file));
            if (null !== $upgrade) {
                // In a Scope of its own, seeded with the request's state as it was at upgrade(), and
                // a frame of its own as a request has: onScopeChange() hooks see it enter
                (new \Scope())->run(static fn () => phasync::run(static function () use ($client, $upgrade): void {
                    SapiRequest::seed($upgrade[1]);
                    self::upgraded($client, $upgrade[0]);
                }));
            }
        };
    }

    /** @return array<string, mixed> $_SERVER for a request: the worker's own, and the request's; PHP adds REQUEST_TIME */
    private static function serverVariables(ClientRequest $client, string $root, string $script, string $name, ?string $info): array
    {
        $target = $client->getTarget();
        $server = [
            'REQUEST_METHOD'     => $client->getMethod(),
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => (string) \parse_url($target, \PHP_URL_QUERY),
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'SCRIPT_FILENAME'    => $script,
            'SCRIPT_NAME'        => $name,
            'PHP_SELF'           => $name . $info,
            'DOCUMENT_ROOT'      => $root,
        ] + self::$server;
        if (null !== $info) {
            $server['PATH_INFO']       = $info;
            $server['PATH_TRANSLATED'] = $root . $info;
        }
        if ('https' === $client->getScheme()) {
            $server['HTTPS'] = 'on';
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->peer(), $m)) {
            [, $server['REMOTE_ADDR'], $server['REMOTE_PORT']] = $m;
        }
        if (\preg_match('/^\[?(.+?)\]?:(\d+)$/', $client->local(), $m)) {
            [, $server['SERVER_ADDR'], $server['SERVER_PORT']] = $m;
        }
        foreach ($client->getRequestHeaders() as $name => $values) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : "HTTP_$key"] = \implode(', ', $values);
        }
        $server['SERVER_NAME'] = \explode(':', $server['HTTP_HOST'] ?? 'localhost')[0];

        return $server;
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
