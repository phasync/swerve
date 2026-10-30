<?php

namespace Swerve\Util;

use Swerve\StopReason;

/**
 * The topics subscribed to in this process, behind Swerve::publish() and Swerve::subscribe().
 *
 * In a swerve worker a message goes in the log every worker shares (SharedLog), which orders
 * every message of every publisher; each process reads it and hands each message to its own
 * subscribers of the topic. Outside swerve (a test, a script), messages stay in this process.
 *
 * Each subscription has an {@see Inbox}: a message wakes only its topic's subscribers, and is
 * kept once, however many subscribe, until the slowest has read it. A topic exists from its
 * first subscription until its last one ends. Delivering starts no coroutine per topic: the
 * process's one reader (SharedLog) hands each message to the inboxes.
 *
 * @internal
 */
final class Topics
{
    /** Longest topic name. */
    public const MAX_TOPIC = 255;
    /** Largest message. */
    public const MAX_MESSAGE = 1 << 17;

    /** The process stops: the early hint of Swerve::draining(). */
    public static bool $draining = false;
    /** The process must now close its connections: subscriptions have ended, see drain(). */
    public static bool $closed = false;

    /** Why the process stops, once drain() was called: a subscription's loop throws it. */
    public static ?StopReason $stopping = null;

    /** @var array<string, array<int, Inbox>> each topic's subscriptions, by object id */
    private static array $inboxes = [];

    /** What a subscriber receives in place of the messages a gap lost. */
    private static ?\stdClass $gap = null;

    /** What publish() sends: $message serialized, after the checks of the topic and the message. */
    public static function encode(string $topic, mixed $message): string
    {
        if (null === $message) {
            throw new \InvalidArgumentException('null is no message: a subscription with a heartbeat yields null when none came');
        }
        try {
            $message = \serialize($message);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('A message must be serializable: ' . $e->getMessage(), 0, $e);
        }
        self::checkSizes($topic, $message);
        return $message;
    }

    /**
     * $message unserialized, with a class the autoloader can't find an Error, as for code: not a
     * __PHP_Incomplete_Class that fails later. Only for this call: the application's own
     * unserialize() keeps PHP's way.
     */
    public static function unserialize(string $message): mixed
    {
        $before = \ini_set('unserialize_callback_func', self::class . '::missing');
        try {
            return \unserialize($message);
        } finally {
            \ini_set('unserialize_callback_func', (string) $before);
        }
    }

    /** @internal unserialize_callback_func: a class the autoloader could not find */
    public static function missing(string $class): never
    {
        throw new \Error("Class \"$class\" not found: a message holds it, and the autoloader of this worker can't find it");
    }

    private static function checkSizes(string $topic, string $message): void
    {
        if ('' === $topic || \strlen($topic) > self::MAX_TOPIC) {
            throw new \InvalidArgumentException('A topic is 1 to ' . self::MAX_TOPIC . ' bytes, not ' . \strlen($topic));
        }
        if (\strlen($message) > self::MAX_MESSAGE) {
            throw new \InvalidArgumentException('A message is at most ' . self::MAX_MESSAGE . ' bytes, not ' . \strlen($message));
        }
    }

    public static function publish(string $topic, mixed $message): void
    {
        self::publishEncoded($topic, self::encode($topic, $message));
    }

    /** Never waits: a publish from a coroutine already cancelled (a goodbye in a finally) still goes out. */
    private static function publishEncoded(string $topic, string $message): void
    {
        if (SharedLog::shared()) {
            SharedLog::append($topic, $message);
        } else {
            self::deliver(0, \hrtime(true), $topic, $message);
        }
    }

    /**
     * Message $seq, published at $published (hrtime()), for this process's subscribers of $topic,
     * if any. A value without objects is unserialized here, once, and shared: scalars and arrays
     * are copies anyway. One that may hold objects or enums (`O:`, `C:` or `E:` in its serialized
     * form; a string merely containing those costs only the copies) goes on serialized, and each
     * subscriber unserializes its own copy: none sees another's changes, and a class this worker's
     * autoloader can't find is an Error in the subscriber's loop, not in the log's reader.
     *
     * Never waits.
     */
    public static function deliver(int $seq, int $published, string $topic, string $message): void
    {
        if (isset(self::$inboxes[$topic])) {
            $shared = !\preg_match('/[OCE]:\d+:"/', $message);
            $item   = [$seq, $published, $shared ? \unserialize($message) : null, $shared ? null : $message];
            foreach (self::$inboxes[$topic] as $inbox) {
                $inbox->push($item);
            }
        }
    }

    /** Messages were lost: every subscriber of this process learns so, at that point of its messages. */
    public static function gap(): void
    {
        $item = [\PHP_INT_MAX, \hrtime(true), self::lost(), null];
        foreach (self::$inboxes as $inboxes) {
            foreach ($inboxes as $inbox) {
                $inbox->push($item);
            }
        }
    }

    /** The value a subscriber receives where messages were lost. */
    public static function lost(): \stdClass
    {
        return self::$gap ??= new \stdClass();
    }

    /** Whether this process has a subscription on the topic. */
    public static function has(string $topic): bool
    {
        return isset(self::$inboxes[$topic]);
    }

    /**
     * A subscription starts.
     *
     * @return array{0: Inbox, 1: int} its inbox, and the sequence number of the last message
     *                                 published before it: it receives the later ones
     */
    public static function subscribe(string $topic): array
    {
        $inbox                                           = new Inbox();
        self::$inboxes[$topic][\spl_object_id($inbox)] = $inbox;

        return [$inbox, SharedLog::shared() ? SharedLog::start(self::deliver(...), self::gap(...)) : -1]; // in this process alone, every message is 0
    }

    /**
     * A subscription ended. After the last one of a topic, the topic is gone. The process goes
     * on reading the log until it drains: it skips what nobody here subscribes to.
     */
    public static function leave(string $topic, Inbox $inbox): void
    {
        // Gone already when the drain ended it
        unset(self::$inboxes[$topic][\spl_object_id($inbox)]);
        if ([] === (self::$inboxes[$topic] ?? null)) {
            unset(self::$inboxes[$topic]);
        }
    }

    /**
     * The process must close its connections (a shutdown or a reload): every subscription ends,
     * so that the long responses fed by them (Server-Sent Events) end too, and the
     * Swerve::onShutdown() callbacks run. A subscription's loop throws a WorkerStoppingException
     * with $reason, as every wait does then; subscriptions made from now on end at once.
     */
    public static function drain(StopReason $reason): void
    {
        self::$draining = self::$closed = true;
        self::$stopping = $reason;
        foreach (self::$inboxes as $inboxes) {
            foreach ($inboxes as $inbox) {
                $inbox->close();
            }
        }
        self::$inboxes = [];
        SharedLog::stop();
        Shutdown::fire();
    }

    /** @return string[] the topics with subscribers in this process */
    public static function active(): array
    {
        return \array_keys(self::$inboxes);
    }
}
