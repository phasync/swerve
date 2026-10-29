<?php

namespace Swerve\Util;

use phasync;
use phasync\ChannelException;
use phasync\SubscribersInterface;
use phasync\WriteChannelInterface;

/**
 * The topics subscribed to in this process, behind Swerve::publish() and Swerve::subscribe().
 *
 * In a swerve worker a message goes in the log every worker shares (SharedLog), which orders
 * every message of every publisher; each process reads it and hands each message to its own
 * subscribers of the topic. Outside swerve (a test, a script), messages stay in this process.
 *
 * Each topic has its own phasync publisher: a message wakes only its topic's subscribers, and
 * is kept once, however many subscribe, until the slowest has read it. A topic exists from its
 * first subscription until its last one ends.
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

    /** @var array<string, SubscribersInterface> */
    private static array $subscribers = [];
    /** @var array<string, WriteChannelInterface> */
    private static array $writers = [];
    /** @var array<string, int> */
    private static array $counts = [];

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
     * The write suspends until the topic's publisher has taken the message. If the last
     * subscriber leaves meanwhile, or the process drains, the publisher is closed and the write
     * throws: nobody is left to deliver to, which is the outcome, not a failure.
     */
    public static function deliver(int $seq, int $published, string $topic, string $message): void
    {
        if (isset(self::$writers[$topic])) {
            try {
                $shared = !\preg_match('/[OCE]:\d+:"/', $message);
                self::$writers[$topic]->write([$seq, $published, $shared ? \unserialize($message) : null, $shared ? null : $message]);
            } catch (ChannelException) {
                // Closed while the write was suspended: the topic has no subscribers left
            }
        }
    }

    /** Messages were lost: every subscriber of this process learns so, at that point of its messages. */
    public static function gap(): void
    {
        foreach (self::$writers as $writer) {
            try {
                $writer->write([\PHP_INT_MAX, \hrtime(true), self::lost(), null]);
            } catch (ChannelException) {
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
        return isset(self::$counts[$topic]);
    }

    /**
     * A subscription starts: its topic's publisher is created with the first one.
     *
     * @return array{0: SubscriberInterface, 1: int} the subscriber, and the sequence number of
     *                                               the last message published before it: it receives the later ones
     */
    public static function subscribe(string $topic): array
    {
        if (!isset(self::$subscribers[$topic])) {
            phasync::publisher(self::$subscribers[$topic], self::$writers[$topic]);
            self::$counts[$topic] = 0;
        }
        ++self::$counts[$topic];
        $subscriber = self::$subscribers[$topic]->subscribe();

        return [$subscriber, SharedLog::shared() ? SharedLog::start(self::deliver(...), self::gap(...)) : -1]; // in this process alone, every message is 0
    }

    /**
     * A subscription ended. After the last one of a topic, its publisher is closed. The process
     * goes on reading the log until it drains: it skips what nobody here subscribes to.
     */
    public static function leave(string $topic): void
    {
        // Gone already when the drain ended it
        if (!isset(self::$counts[$topic]) || --self::$counts[$topic] > 0) {
            return;
        }
        $writer = self::$writers[$topic];
        unset(self::$subscribers[$topic], self::$writers[$topic], self::$counts[$topic]);
        $writer->close();
    }

    /**
     * The process must close its connections (a shutdown or a reload): every subscription ends,
     * so that the long responses fed by them (Server-Sent Events) end too, and the
     * Swerve::onShutdown() callbacks run. Subscriptions made from now on end at once.
     */
    public static function drain(): void
    {
        self::$draining = self::$closed = true;
        foreach (self::$writers as $writer) {
            $writer->close();
        }
        self::$subscribers = self::$writers = self::$counts = [];
        SharedLog::stop();
        Shutdown::fire();
    }

    /** @return string[] the topics with subscribers in this process */
    public static function active(): array
    {
        return \array_keys(self::$counts);
    }
}
