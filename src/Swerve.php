<?php

namespace Swerve;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swerve\Util\RequestSwitch;
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
 * @see Swerve\Claim
 * @see Swerve\Subscription
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
     */
    public static function setLog(LoggerInterface $log): void
    {
        self::$log = $log;
    }

    /**
     * Send a message to every subscriber of `$topic`, in every worker process of this swerve, this one included.
     *
     * Returns once the message is on its way, not once delivered. Delivery is at most once, to the
     * subscriptions that exist when the message reaches their worker: there is no history, and a
     * worker starting later (after a reload, a recycle, a crash) sees nothing sent before. The
     * messages of one publishing worker arrive in the order it published them, with no order
     * between workers. A worker that does not read its inbox loses the messages sent to it after
     * 0.1 s. Swerve embedded without its master process delivers in this process only.
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
     * @see Swerve\OrderedChannel   when order between workers matters
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
     * $user  = $cache->get("user:$id") ?? load_user($id);
     * $cache->set("user:$id", $user, 60);
     * ```
     *
     * @return \Psr\SimpleCache\CacheInterface the same cache on every call
     *
     * @see Swerve\Cache
     * @see Swerve::claim   when one worker at a time must do something
     */
    public static function cache(): \Psr\SimpleCache\CacheInterface
    {
        return Cache::instance();
    }

    /**
     * A handle on the name `$name`, which one holder at a time can hold across the workers.
     *
     * Nothing is claimed until `acquire()` is called: `Swerve::claim('name')->acquire()` is null,
     * or the held handle. It is held until released, destroyed, or its worker exits or dies.
     *
     * ```php
     * if ($claim = Swerve::claim('nightly-report')->acquire()) {
     *     run_report();
     * }                                     // released when $claim goes out of scope
     * ```
     *
     * @param string $name any non-empty string; a namespace of its own, apart from the cache's keys
     *
     * @throws \InvalidArgumentException for an empty name
     *
     * @see Swerve\Claim
     * @see Swerve::cache
     */
    public static function claim(string $name): Claim
    {
        return new Claim($name);
    }

    /**
     * Whether this worker drains: it is shutting down, reloading or being recycled, and finishes the requests in flight.
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
     * passed already.
     *
     * The callback belongs to the request (or coroutine context) that registered it, and goes
     * when that request ends, without running: swerve holds no reference to the request. phasync
     * collects the cycles half a second after a coroutine ends, so what the callback holds is
     * freed shortly after.
     *
     * ```php
     * YourProtocol::from($request, function (YourProtocol $conn) {
     *     Swerve::onShutdown(fn () => $conn->end('restarting'));
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
     */
    public static function awaitShutdown(?float $timeout = null): bool
    {
        return Shutdown::await($timeout);
    }

    /**
     * Run `$resume`/`$suspend` around a switch between coroutines of two different requests, so a
     * framework can keep process-wide PHP state (`setlocale()`, `date_default_timezone_set()`,
     * `mb_internal_encoding()`, ...) as its own per request, letting application code use the
     * plain functions.
     *
     * Call once at boot (`swerve.php`, or an adapter's entry point), before any request is
     * served. From then on, every request's `$resume($request)` runs once, as its first
     * coroutine starts (that is its own one call, with no earlier request to pair with); from
     * then on, it runs again only when a coroutine of a *different* request ran since - never
     * after the event loop's own code, and never between two coroutines of the same request (its
     * own `phasync::go()` children share its context). `$suspend($request)` runs just before a
     * coroutine of a different request is about to run, in the same way: a request that never
     * shares a worker tick with another gets none. Nothing here costs anything until this is
     * called at least once; from then on, every request's first coroutine enters its context
     * (which it would otherwise only do lazily, on its own first `phasync::getContext()`,
     * `go()` or `finally()`), so that resume/suspend can bracket it correctly from the start.
     *
     * Several registrations run in registration order on resume and reverse order on suspend, as
     * nested scopes would. Each closure runs on the switch path, in a coroutine of its own: it
     * must return without waiting (no `phasync::sleep()`, no await) or it is logged and abandoned
     * instead of hanging the switch. Any other exception is logged the same way and never thrown
     * into a request.
     *
     * ```php
     * $locales = new WeakMap();
     * Swerve::onRequestSwitch(
     *     resume: function (ClientRequest $request) use ($locales) {
     *         setlocale(LC_ALL, $locales[$request] ?? 'C');
     *     },
     *     suspend: function (ClientRequest $request) use ($locales) {
     *         $locales[$request] = setlocale(LC_ALL, 0);
     *     },
     * );
     * ```
     *
     * @param \Closure(ClientRequest):void $resume  a coroutine of $request is about to run
     * @param \Closure(ClientRequest):void $suspend a coroutine of a different request is about to run
     *
     * @see phasync\Context\SwitchAwareInterface
     */
    public static function onRequestSwitch(\Closure $resume, \Closure $suspend): void
    {
        RequestSwitch::listen($resume, $suspend);
    }

    /**
     * php.ini settings a package needs in every swerve process. Call it from a file listed in
     * your package's composer.json `files`: Composer loads it after swerve's own, as the master
     * starts. swerve applies the settings with its startup restart, over its own settings; an
     * explicit `-d` on the command line still wins.
     *
     * Calls after startup change nothing, so the file may run again: an application that
     * includes Composer's autoloader in every request (under `virtualize()`) includes it again.
     *
     * ```php
     * Swerve::ini(['phasync.virtualize' => true]);
     * ```
     *
     * @param array<string, string|int|float|bool> $settings
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
     * @param \Closure():void $callback
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
     * The installed version, as Composer knows it: `0.1.0`, or `dev-main` in a checkout.
     *
     * @return string the version, or 'unknown' when Composer has none for `phasync/swerve`
     */
    public static function getVersion(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('phasync/swerve') ?? 'unknown';
    }
}
