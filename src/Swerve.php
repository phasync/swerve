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
     * A message travels serialized (`serialize()`): any bytes, arrays, enums and objects. A
     * subscriber gets the value published: scalars and arrays unserialized once per worker, and a
     * value holding objects as a copy of its own for each subscriber, so none sees another's
     * changes. The autoloader finds the classes: one the subscriber's worker can't find is an
     * `Error` from the subscriber's loop.
     *
     * ```php
     * Swerve::publish('game', ['kill', $playerId]);
     * Swerve::publish('frames', $binaryWebSocketFrame);   // bytes stay bytes
     * ```
     *
     * @param string $topic   1 to 255 bytes
     * @param mixed  $message anything `serialize()` takes except null; at most 128 KiB serialized
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes, a topic starting with "\0", null (which a heartbeat subscription yields for "nothing came"), or a value that can't be serialized (a closure)
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
     * request waiting, and return the request, which is served in a coroutine of its own and is
     * ready once answered (await it to serve one at a time). The connection then goes back to
     * the master, which hands its next request to whichever worker asks first.
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
     *                                         superglobals and php://input as under php-fpm; a
     *                                         `101` to an Upgrade request takes the connection
     *                                         (the response body goes out, the request body is
     *                                         what the client sends after it), and needs an
     *                                         accept() loop
     *
     * @return Accepted the request taken on; awaiting it throws what its handler threw
     *
     * @throws WorkerStoppingException once the worker stops: swerve.php ends there
     * @throws \LogicException         when called inside a request
     */
    public static function accept(?string $staticPath, string|\Closure|null $handler): Accepted
    {
        if ('swerve' === \php_sapi_name()) {
            throw new \LogicException('Swerve::accept() is called by the worker\'s swerve.php, not inside a request');
        }
        if (!self::$accepting) {
            self::$accepting = true;
            \register_shutdown_function(static function () { HttpConnection::$exiting = true; });
            require_once __DIR__ . '/Util/finish_request.php';
        }

        $files = null === $staticPath ? null : self::$staticFiles[$staticPath] ??= new StaticFiles($staticPath);

        return LotClient::get()->accept(self::handler($handler, $files?->root), $files);
    }

    /**
     * Complete the response now and let the request's code run on, as php-fpm's
     * `fastcgi_finish_request()`: what the output buffers hold is sent, the response ends, and the
     * connection goes on with the client's next request (or closes) while the code finishes its
     * work. From then on its output goes nowhere and `connection_aborted()` is true.
     *
     * In a worker, `fastcgi_finish_request()` and `litespeed_finish_request()` call it, so
     * frameworks that use them (Symfony's and Laravel's terminate step) finish early unchanged. A
     * response also completes by itself once its body reached its `Content-Length`.
     *
     * ```php
     * echo json_encode(['queued' => true]);
     * Swerve::finishRequest();     // the client has its answer
     * sendTheMails();              // the request runs on, in its Scope
     * ```
     *
     * @return bool false outside a request, after upgrade(), or when the response was complete already
     */
    public static function finishRequest(): bool
    {
        return SapiRequest::finish();
    }

    /**
     * Take the connection off the request being served, for $handler: a WebSocket, a stream of
     * server-sent events, or any response written to the ClientRequest. The type of $handler's
     * parameter says which.
     *
     * Without $bootstrap, $handler runs here, in the request, which waits for it: its code, its
     * Scope and its state (superglobals, session, globals, the application's objects) are the
     * handler's for as long as the connection is open, and upgrade() returns once it closed. From
     * the call on, the request has no connection: connection_aborted() is true, header() has no
     * effect, and output throws. $handler is any callable, a closure capturing what it likes.
     *
     * A swerve.php that accepts one request per run (WordPress's) is busy for as long as an
     * inline WebSocket stays open: a worker serves one at a time. With $bootstrap, the WebSocket
     * moves instead, its `101` sent here, to one of swerve's WebSocket workers: processes that
     * loaded $bootstrap once, as they started, and then serve many WebSockets each. Each file has
     * its own WebSocket workers, up to one per worker, and every WebSocket a worker moves with
     * that file goes to the same one, started the first time it needs to. They take part in
     * {@see Swerve::publish()}, {@see Swerve::subscribe()}, {@see Swerve::cache()} and
     * {@see Swerve::container()} as the workers do: $bootstrap adds the services they need to the
     * container, and limits count across every process.
     *
     * What moves is $handler and the request's state as it was at this call: $_GET, $_POST,
     * $_COOKIE, $_FILES, $_REQUEST, $_SERVER, $_SESSION (the data; the session itself was written
     * and closed with the request), the default timezone and the intl locale. $handler runs in a
     * Scope of its own, seeded with it. A moving $handler is the name of a function, or a class
     * and a static method, which $bootstrap or the Composer autoload defines; a static closure
     * moves too when phasync/serializor is installed, serialized with what it captures.
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
     * @param \Closure|string|array{0: class-string, 1: string} $handler   given a WebSocket, a
     *                                                                     ServerSentEvents or the ClientRequest; moving, a WebSocket
     * @param string|null                                       $bootstrap the file the WebSocket workers load as they start: given,
     *                                                                     the WebSocket moves to one of them
     *
     * @throws \TypeError        for a handler that is not callable, or takes something else; a
     *                           moving one that is not a function's or static method's name, or a static closure
     * @throws UpgradeException  when the request can't be upgraded so: a WebSocket handler for a
     *                           request that is no valid WebSocket handshake
     * @throws \LogicException   outside a request, a second time, once output began (also into
     *                           an output buffer), for a $bootstrap that is no file, or for a moving
     *                           closure without phasync/serializor, or whose state can't be serialized
     */
    public static function upgrade(\Closure|string|array $handler, ?string $bootstrap = null): void
    {
        if (null === $bootstrap) {
            $handler = \Closure::fromCallable($handler);
            $type    = (new \ReflectionFunction($handler))->getParameters()[0] ?? null;
            $type    = $type?->getType();
            if (!$type instanceof \ReflectionNamedType || !\in_array($type->getName(), [WebSocket::class, ServerSentEvents::class, ClientRequest::class], true)) {
                throw new \TypeError('Swerve::upgrade() takes a handler whose parameter is a ' . WebSocket::class . ', a ' . ServerSentEvents::class . ' or a ' . ClientRequest::class);
            }
            $client = SapiRequest::client();
            if (WebSocket::class === $type->getName()) {
                self::handshake($client);
            }
            SapiRequest::upgrade();
            if ($client instanceof HttpClientRequest) {
                ($client->onUpgrade)?->__invoke();
            }
            self::upgraded($client, $handler);

            return;
        }
        if ($handler instanceof \Closure) {
            if (!\class_exists(Codec::class)) {
                throw new \LogicException('Swerve::upgrade(): a closure moves to the WebSocket worker with phasync/serializor (composer require phasync/serializor); or pass the name of a function, or [class, static method]');
            }
            if (null !== (new \ReflectionFunction($handler))->getClosureThis()) {
                throw new \TypeError('Swerve::upgrade() moves a static closure, without $this');
            }
        } elseif (!\is_string($handler) && !(\array_is_list($handler) && 2 === \count($handler) && \is_string($handler[0]) && \is_string($handler[1]))) {
            throw new \TypeError('Swerve::upgrade() moves the name of a function, [class, static method], or a static closure');
        }
        $file = \realpath($bootstrap);
        if (false === $file || !\is_file($file)) {
            throw new \LogicException("Swerve::upgrade(): the bootstrap file $bootstrap does not exist");
        }
        $client    = SapiRequest::client();
        $handshake = self::handshake($client);
        $moving    = [$handler, SapiRequest::state(), $handshake->headers['sec-websocket-extensions'] ?? null];
        try {
            $state = $handler instanceof \Closure ? 'c' . (new Codec())->serialize($moving) : 's' . \serialize($moving);
        } catch (\Throwable $e) {
            throw new \LogicException('Swerve::upgrade(): the WebSocket moves to the WebSocket worker, and what goes with it can\'t be serialized: ' . $e->getMessage(), 0, $e);
        }
        if (\strlen($state) > LotClient::MAX_MOVE) {
            throw new \LogicException('Swerve::upgrade(): the WebSocket moves to the WebSocket worker, and serialized with what goes with it, it is ' . \strlen($state) . ' bytes, more than ' . LotClient::MAX_MOVE);
        }
        SapiRequest::upgrade();
        if (null !== WebSocket::answer($client)) {
            LotClient::get()->moveWebSocket($client, $file, $state);
        }
    }

    /** The request's WebSocket handshake, accepted, or an UpgradeException. */
    private static function handshake(ClientRequest $client): WebSocketHandshake
    {
        $handshake = WebSocket::handshake($client->getMethod(), $client->getProtocolVersion(), $client->getRequestHeaders(), !$client->eof());
        if (!$handshake->accepted()) {
            throw new UpgradeException("Not a WebSocket handshake: $handshake->body", $handshake->status, $handshake->headers, $handshake->body);
        }

        return $handshake;
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

    /** The connection, taken off the request, for $handler. */
    private static function upgraded(ClientRequest $client, \Closure $handler): void
    {
        match ((new \ReflectionFunction($handler))->getParameters()[0]->getType()->getName()) {
            WebSocket::class        => WebSocket::from($client, $handler),
            ServerSentEvents::class => $handler(new ServerSentEvents($client)),
            default                 => $handler($client),
        };
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
            SapiRequest::run($client, self::serverVariables($client, $root, $file, $name, $info), static fn () => $code($client, $file));
        };
    }

    /**
     * $_SERVER for a request, as php-fpm gives it by default (clear_env): the request's entries, not
     * the worker's environment, which getenv() reads. PHP adds REQUEST_TIME.
     *
     * @return array<string, mixed>
     */
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
        ];
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
