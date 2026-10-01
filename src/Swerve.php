<?php

namespace Swerve;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swerve\Util\Topics;

/**
 * What swerve gives the application: its log, the cache and the messages shared by every worker
 * (see the methods), and the state of the worker.
 */
final class Swerve
{
    private function __construct()
    {
    }

    /** The log of this process, see log(). */
    private static ?LoggerInterface $log = null;

    /**
     * The log of this process: swerve's own, in its format (the time, this worker's slot),
     * wherever swerve logs (the terminal, or --log's file); a NullLogger with -q. Give it to
     * the libraries that take a PSR-3 logger, such as Slim's error middleware:
     *
     *     $app->addErrorMiddleware(true, true, false, Swerve::log());
     *
     * Without swerve's command line (swerve embedded), a NullLogger until setLog() is called.
     */
    public static function log(): LoggerInterface
    {
        return self::$log ??= new NullLogger();
    }

    /**
     * @internal set by bin/swerve
     */
    public static function setLog(LoggerInterface $log): void
    {
        self::$log = $log;
    }

    /**
     * Send a message to every subscriber of $topic, in every worker process of this swerve,
     * this one included. Returns once the message is on its way, not once delivered.
     *
     * Delivery is at most once, to the subscriptions that exist when the message reaches their
     * worker: there is no history, and a worker starting later (after a reload, a recycle, a
     * crash) sees nothing sent before. The messages of one publishing worker arrive in the order
     * it published them, with no order between workers. A worker that does not read its inbox
     * loses the messages sent to it after 0.1 s. Swerve embedded without its master process
     * delivers in this process only.
     *
     * Every message travels as JSON: encoded once here, decoded once in each worker, and every
     * subscriber gets the value published, shared: a string stays a string ('{}' too), a list
     * an array, and an object (an array with keys) a read-only SealedObject: `$message->end`.
     *
     *     Swerve::publish('game', ['kill', $playerId]);
     *
     * @param string $topic   1 to 255 bytes
     * @param mixed  $message anything json_encode() takes except null; at most 128 KiB encoded
     *
     * @throws \InvalidArgumentException for a topic or message outside those sizes, or a topic starting with "\0"
     * @throws \LogicException            while the application loads: the worker serves after that
     * @throws \JsonException            for a value JSON can't express
     * @throws \InvalidArgumentException for null, which a heartbeat subscription yields for "nothing came"
     */
    public static function publish(string $topic, mixed $message): void
    {
        self::refuseInternal($topic);
        Topics::publish($topic, $message);
    }

    /**
     * Receive what is published to $topic from now on:
     *
     *     foreach (Swerve::subscribe('chat') as $message) { ... }
     *
     * The subscription ends when its last reference goes, and its loop when the process drains,
     * see Subscription. One falling more than $maxLag seconds behind gets a
     * SubscriberLagException from the loop. With $heartbeat, the loop also gets null after
     * that many seconds without a message: for a keep-alive.
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
     * The cache every worker shares, held by the master: see Cache.
     */
    public static function cache(): \Psr\SimpleCache\CacheInterface
    {
        return Cache::instance();
    }

    /**
     * A handle on the name $name, one holder at a time across the workers; claims nothing until
     * acquire() is called: `Swerve::claim('name')->acquire()` is null, or the held handle. It is
     * held until released, destroyed, or its worker exits or dies. See Claim.
     */
    public static function claim(string $name): Claim
    {
        return new Claim($name);
    }

    /**
     * Whether this worker drains: it is shutting down, reloading or being recycled, and finishes
     * the requests in flight. Long responses should end soon: see docs/realtime.md.
     */
    public static function draining(): bool
    {
        return Topics::$draining;
    }

    /** The installed version, as Composer knows it: 0.1.0-alpha3, or dev-main in a checkout. */
    public static function getVersion(): string
    {
        return \Composer\InstalledVersions::getPrettyVersion('phasync/swerve') ?? 'unknown';
    }
}
