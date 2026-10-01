<?php

namespace Swerve;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swerve\Util\Topics;

/**
 * What swerve gives the application: its log, the cache and the messages shared by every worker, and the state of the worker.
 *
 * Every method is static. Workers share no memory: the cache and the messages go through the
 * master process, and work once the worker serves, not while `swerve.php` loads.
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

    /**
     * The log of this process: swerve's own PSR-3 logger, in its format (the time, this worker's slot).
     *
     * It writes wherever swerve logs (the terminal, or `--log`'s file), and is a `NullLogger` with
     * `-q`. Without swerve's command line (swerve embedded), it is a `NullLogger` until
     * {@see Swerve::setLog()} is called. Give it to the libraries that take a PSR-3 logger, such
     * as Slim's error middleware:
     *
     * ```php
     * $app->addErrorMiddleware(true, true, false, Swerve::log());
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
     * @throws \LogicException           while the application loads: the worker serves after that
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
     * Long responses should end soon: see docs/realtime.md. A {@see Subscription} ends its loop
     * when the worker drains.
     *
     * ```php
     * while (!Swerve::draining()) {
     *     $out->append(": keep-alive\n\n");
     *     phasync::sleep(15);
     * }
     * ```
     *
     * @see Swerve::subscribe
     */
    public static function draining(): bool
    {
        return Topics::$draining;
    }

    /**
     * The installed version, as Composer knows it: `0.1.0-alpha3`, or `dev-main` in a checkout.
     *
     * @return string the version, or 'unknown' when Composer has none for `phasync/swerve`
     */
    public static function getVersion(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('phasync/swerve') ?? 'unknown';
    }
}
