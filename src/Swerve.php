<?php

namespace Swerve;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Serializor\Codec;
use Swerve\Http\HttpClientRequest;
use Swerve\Http\HttpConnection;
use Swerve\Http\SapiRequest;
use Swerve\Lot\LotClient;
use Swerve\Psr\Psr7;
use Swerve\Util\Shutdown;
use Swerve\Util\Topics;

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
     * @param ?string         $staticPath the directory of static files (as {@see StaticFiles}); null: none
     * @param string|\Closure $handler a script's path: run as a fresh main script, its variables
     *                                 globals, echo and header() the response; a closure without
     *                                 parameters: likewise; a closure taking a
     *                                 ServerRequestInterface: returns a ResponseInterface, also
     *                                 under sapi(), its request made from the superglobals and
     *                                 php://input as under php-fpm
     *
     * @return ?\Fiber the coroutine serving the request; null once the worker stops
     *
     * @throws \LogicException when called inside a request
     */
    public static function accept(?string $staticPath, string|\Closure $handler): Accepted
    {
        if ('swerve' === \php_sapi_name()) {
            throw new \LogicException('Swerve::accept() is called by the worker\'s swerve.php, not inside a request');
        }
        if (!self::$accepting) {
            self::$accepting = true;
            \register_shutdown_function(static function () { HttpConnection::$exiting = true; });
        }

        if (null !== $staticPath) {
            $files = self::$staticFiles[$staticPath] ??= new StaticFiles($staticPath);
        }

        return LotClient::get()->accept(self::handler($handler), $files ?? null);
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
     * moves on to swerve's WebSocket worker: its `101` is sent here, and the closure, serialized
     * with phasync/serializor along with what it captures, runs in that process, which has the
     * application's autoloader and nothing else of it.
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
     * @throws \TypeError     for a closure that is not static, or takes something else
     * @throws \LogicException outside a request, a second time, or once output or headers went out
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
        SapiRequest::upgrade($upgrade);
    }

    /** @return \Closure(ClientRequest): void */
    private static function handler(string|\Closure $handler): \Closure
    {
        if (\is_string($handler)) {
            $script = \realpath($handler);
            if (false === $script || !\is_file($script)) {
                throw new \ValueError("Swerve::accept(): the script $handler does not exist");
            }

            return self::sapi($script, static function () use ($script): void {
                \chdir(\dirname($script));
                \phasync\ext\include_global($script);
            });
        }
        $params = (new \ReflectionFunction($handler))->getParameters();
        if ([] === $params) {
            return self::sapi($_SERVER['SCRIPT_FILENAME'] ?? '', $handler);
        }
        $type = $params[0]->getType();
        if ($type instanceof \ReflectionNamedType && \is_a($type->getName(), ServerRequestInterface::class, true)) {
            return self::sapi($_SERVER['SCRIPT_FILENAME'] ?? '', static fn (ClientRequest $client) => Psr7::serve($client, $handler));
        }
        throw new \TypeError('Swerve::accept(): a handler closure takes no parameters, or a ' . ServerRequestInterface::class . ' first');
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
            // A lifecycle that has called accept() only once may be the kind that ends after each
            // request (WordPress's): the WebSocket moves on to swerve's WebSocket worker
            WebSocket::class        => LotClient::get()->shortLived() ? self::moveWebSocket($client, $upgrade) : WebSocket::from($client, $upgrade),
            ServerSentEvents::class => $upgrade(new ServerSentEvents($client)),
            default                 => $upgrade($client),
        };
    }

    /** Answer the handshake here, and move the connection and $upgrade to the WebSocket worker. */
    private static function moveWebSocket(HttpClientRequest $client, \Closure $upgrade): void
    {
        if (null !== $handshake = WebSocket::answer($client)) {
            $moving = [$upgrade, $handshake->subprotocol]; // serialize() takes a reference
            LotClient::get()->moveWebSocket($client, (new Codec())->serialize($moving));
        }
    }

    /**
     * Serve with $code under sapi(), with the superglobals PHP gives a script: $_SERVER for
     * $script, then $_GET, $_COOKIE, $_POST, $_FILES and $_REQUEST.
     *
     * @param \Closure(ClientRequest): void $code
     *
     * @return \Closure(ClientRequest): void
     */
    private static function sapi(string $script, \Closure $code): \Closure
    {
        return static function (ClientRequest $client) use ($script, $code): void {
            $_SERVER = self::serverVariables($client, $script);
            $upgrade = SapiRequest::run($client, static function () use ($client, $code): void {
                self::requestVariables($client);
                $code($client);
            });
            if (null !== $upgrade) {
                self::upgraded($client, $upgrade);
            }
        };
    }

    /** @return array<string, mixed> $_SERVER for a request: the worker's own, and the request's */
    private static function serverVariables(ClientRequest $client, string $script): array
    {
        static $worker = null;
        $worker ??= $_SERVER;
        $target   = $client->getTarget();
        $now      = \microtime(true);
        $server   = [
            'REQUEST_METHOD'     => $client->getMethod(),
            'REQUEST_URI'        => $target,
            'QUERY_STRING'       => (string) \parse_url($target, \PHP_URL_QUERY),
            'SERVER_PROTOCOL'    => 'HTTP/' . $client->getProtocolVersion(),
            'SCRIPT_FILENAME'    => $script,
            'SCRIPT_NAME'        => '/' . \basename($script),
            'PHP_SELF'           => '/' . \basename($script),
            'DOCUMENT_ROOT'      => '' === $script ? '' : \dirname($script),
            'REQUEST_TIME'       => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ] + $worker;
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

    /** $_GET, $_COOKIE, $_POST, $_FILES and $_REQUEST, inside the request's Scope and sapi(). */
    private static function requestVariables(ClientRequest $client): void
    {
        \parse_str((string) \parse_url($client->getTarget(), \PHP_URL_QUERY), $_GET);
        $_COOKIE = [];
        foreach ($client->getRequestHeaders()['cookie'] ?? [] as $header) {
            foreach (\explode(';', $header) as $pair) {
                [$name, $value] = \explode('=', \trim($pair), 2) + [1 => ''];
                if ('' !== $name) {
                    $_COOKIE[\urldecode($name)] ??= \urldecode($value);
                }
            }
        }
        $_POST  = $_FILES = [];
        $type   = $client->getRequestHeaders()['content-type'][0] ?? '';
        if (\preg_match('#^(application/x-www-form-urlencoded|multipart/form-data)\b#i', $type)) {
            [$_POST, $_FILES] = \request_parse_body();
        }
        $_REQUEST = $_GET + $_POST;
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
