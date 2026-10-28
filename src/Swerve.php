<?php

namespace Swerve;

use phasync;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Serializor\Codec;
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

    /** Topics starting with "\0" are swerve's own (Inboxes::TOPIC, among others). */
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
     * in that process, which has the application's autoloader and nothing else of it.
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
                $moving = [$upgrade, SapiRequest::state()];
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
    private static function handler(string|\Closure $handler, ?string $root): \Closure
    {
        if (\is_string($handler)) {
            $script = \realpath($handler);
            if (false === $script || !\is_file($script)) {
                throw new \ValueError("Swerve::accept(): the script $handler does not exist");
            }
            $root ??= \dirname($script);
            $name   = \str_starts_with($script, "$root/") ? \substr($script, \strlen($root)) : '/' . \basename($script);

            return self::sapi($root, static fn (ClientRequest $client) => self::route($client, $root) ?? [$script, $name, null], static function (ClientRequest $client, string $file): void {
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
            [$file, $name, $info] = $route($client);
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
