<?php

namespace Swerve\Util;

use Closure;
use phasync;
use phasync\SubscribersInterface;
use phasync\WriteChannelInterface;

/**
 * The topics subscribed to in this process, behind Swerve::publish() and Swerve::subscribe().
 *
 * In a worker, a message goes to the master (see Worker::publish()), which sends it to every
 * worker, this one included; each delivers it to its own subscribers. The master is the one
 * place messages pass in order, so every subscriber sees them in the same order. Without a
 * master (swerve embedded, no Cluster), messages are delivered in this process only.
 *
 * Each topic has its own phasync publisher: a message wakes only its topic's subscribers, and
 * is kept once, however many subscribe, until the slowest has read it. A topic exists from its
 * first subscription until its last one ends.
 *
 * @internal
 */
final class Topics
{
    /** Longest topic name: its length goes in one byte of the frame. */
    public const MAX_TOPIC = 255;
    /** Largest message. */
    public const MAX_MESSAGE = 1 << 20;

    /**
     * Sends a message to the master, set by the Worker; null without a master.
     *
     * @var (Closure(string $topic, string $message): void)|null
     */
    public static ?Closure $toMaster = null;

    /** The process drains: subscriptions have ended, see drain(). */
    public static bool $draining = false;

    /** @var array<string, SubscribersInterface> */
    private static array $subscribers = [];
    /** @var array<string, WriteChannelInterface> */
    private static array $writers = [];
    /** @var array<string, int> */
    private static array $counts = [];

    public static function publish(string $topic, string $message): void
    {
        if ('' === $topic || \strlen($topic) > self::MAX_TOPIC) {
            throw new \InvalidArgumentException('A topic is 1 to ' . self::MAX_TOPIC . ' bytes, not ' . \strlen($topic));
        }
        if (\strlen($message) > self::MAX_MESSAGE) {
            throw new \InvalidArgumentException('A message is at most ' . self::MAX_MESSAGE . ' bytes, not ' . \strlen($message));
        }
        if (null !== self::$toMaster) {
            (self::$toMaster)($topic, $message);
        } else {
            self::deliver($topic, $message);
        }
    }

    /**
     * Hand a message to this process's subscribers of its topic, if any. Each message carries
     * when it arrived, for Subscription's lag check.
     */
    public static function deliver(string $topic, string $message): void
    {
        if (isset(self::$writers[$topic])) {
            self::$writers[$topic]->write([\hrtime(true), $message]);
        }
    }

    /** A subscription starts: its topic's subscribers, created with the first one. */
    public static function join(string $topic): SubscribersInterface
    {
        if (!isset(self::$subscribers[$topic])) {
            phasync::publisher(self::$subscribers[$topic], self::$writers[$topic]);
            self::$counts[$topic] = 0;
        }
        ++self::$counts[$topic];

        return self::$subscribers[$topic];
    }

    /**
     * A subscription ended. After the last one the topic is forgotten first, so that a
     * subscription starting meanwhile gets a publisher of its own, then its publisher is closed,
     * which ends its service coroutine.
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
     * A message as it goes over the pipes between master and workers, among the single status
     * bytes (see Cluster): 'P', the topic's length (1 byte), the message's (4 bytes, big
     * endian), the topic, the message.
     */
    public static function frame(string $topic, string $message): string
    {
        return 'P' . \chr(\strlen($topic)) . \pack('N', \strlen($message)) . $topic . $message;
    }

    /**
     * Take the complete frames and status bytes off the front of $buffer: calls $message for
     * each frame, with the frame itself last, and returns the status bytes. An incomplete frame
     * stays in $buffer for the next read.
     *
     * @param Closure(string $topic, string $message, string $frame): void $message
     */
    public static function parse(string &$buffer, Closure $message): string
    {
        $status = '';
        $at     = 0;
        $length = \strlen($buffer);
        while ($at < $length) {
            if ('P' !== $buffer[$at]) {
                $status .= $buffer[$at++];
                continue;
            }
            if ($length - $at < 6) {
                break;
            }
            $topicLength = \ord($buffer[$at + 1]);
            $frameLength = 6 + $topicLength + \unpack('N', $buffer, $at + 2)[1];
            if ($length - $at < $frameLength) {
                break;
            }
            $message(\substr($buffer, $at + 6, $topicLength), \substr($buffer, $at + 6 + $topicLength, $frameLength - 6 - $topicLength), \substr($buffer, $at, $frameLength));
            $at += $frameLength;
        }
        $buffer = \substr($buffer, $at);

        return $status;
    }

    /**
     * The process drains (shutdown, reload, recycle): every subscription ends, so that the
     * long responses fed by them (Server-Sent Events) end too, instead of holding the drain up
     * to its deadline. Subscriptions made from now on end at once.
     */
    public static function drain(): void
    {
        self::$draining = true;
        foreach (self::$writers as $writer) {
            $writer->close();
        }
        self::$subscribers = self::$writers = self::$counts = [];
    }

    /** @return string[] the topics with subscribers in this process */
    public static function active(): array
    {
        return \array_keys(self::$counts);
    }
}
