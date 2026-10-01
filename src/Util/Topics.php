<?php

namespace Swerve\Util;

use Closure;
use phasync;
use phasync\SubscriberInterface;
use phasync\SubscribersInterface;
use phasync\TimeoutException;
use phasync\Util\LruCache;
use phasync\WriteChannelInterface;
use Psr\Log\LoggerInterface;
use Swerve\Cache;

/**
 * The topics subscribed to in this process, behind Swerve::publish() and Swerve::subscribe().
 *
 * In a worker, a message is written as a datagram straight to the inbox of every worker that
 * has a subscriber on the topic (see Inboxes), this one included, without the master; each
 * delivers it to its own subscribers. A worker learns who subscribes from the master, as a
 * bitmap per topic, and keeps the answer until the master says to forget it. Messages from one
 * worker arrive at another in the order they were published; there is no order across senders.
 * Without a master (swerve embedded, no Cluster), messages are delivered in this process only.
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
    /** Largest message: a datagram carries it whole, and the sockets' buffers are about 200 KiB. */
    public const MAX_MESSAGE = 1 << 17;
    /** Largest datagram: the topic's length, the topic and the message. */
    public const MAX_DATAGRAM = self::MAX_MESSAGE + self::MAX_TOPIC + 1;
    /** How long a worker that does not read its inbox is waited for, before the messages for it are dropped. */
    public const SEND_WAIT = 0.1;
    /** What a worker keeps of the master's bitmaps. */
    public const BITMAP_BYTES = 1 << 20;

    /**
     * Sends a message to the master, set by the Worker; null without a master.
     *
     * @var (Closure(string $topic, string $message, bool $json): void)|null
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

    /** This worker's inbox: its bit in the bitmaps. */
    private static int $own = 0;
    /** @var array<int, resource> the other workers' inboxes, by inbox */
    private static array $peers = [];
    /** What the master told: topic => bitmap, '' for a topic nobody subscribes to. */
    private static ?LruCache $bitmaps = null;
    private static ?LoggerInterface $logger = null;
    private static int $next = 0;
    /** @var array<int, \stdClass> callers waiting for the master's reply, by request id */
    private static array $waiting = [];
    /** @var array<string, \stdClass> a subscription not yet acknowledged by the master, by topic */
    private static array $acks = [];
    /** @var array<int, \SplQueue<string>> datagrams a full inbox did not take, by inbox; its flusher coroutine runs while there are any */
    private static array $outbox = [];
    /** @var array<int, true> inboxes that have dropped messages, until one is written again: one warning per episode */
    private static array $dropped = [];

    /**
     * In a worker: its inbox, and the others' to write to. Needs Topics::$toMaster.
     *
     * @param array<int, resource> $peers
     */
    public static function connect(int $own, array $peers, LoggerInterface $logger): void
    {
        self::$own     = $own;
        self::$peers   = $peers;
        self::$logger  = $logger;
        self::$bitmaps = new LruCache(maxBytes: self::BITMAP_BYTES);
    }

    /**
     * What publish() sends and OrderedChannel::write() writes: $message as JSON, after the checks of
     * the topic and the message.
     */
    public static function encode(string $topic, mixed $message): string
    {
        if (null === $message) {
            throw new \InvalidArgumentException('null is no message: a subscription with a heartbeat yields null when none came');
        }
        // Always JSON, strings too: what a subscriber gets is the value published ('{}' stays a
        // string), decoded once per worker
        // Depth 511: json_decode() at its default 512 fails on the 512 levels json_encode() accepts,
        // so what the workers could not decode fails here, in the publisher
        $message = \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION, 511);
        if ('' === $topic || \strlen($topic) > self::MAX_TOPIC) {
            throw new \InvalidArgumentException('A topic is 1 to ' . self::MAX_TOPIC . ' bytes, not ' . \strlen($topic));
        }
        if (\strlen($message) > self::MAX_MESSAGE) {
            throw new \InvalidArgumentException('A message is at most ' . self::MAX_MESSAGE . ' bytes, not ' . \strlen($message));
        }

        return $message;
    }

    public static function publish(string $topic, mixed $message): void
    {
        $message = self::encode($topic, $message);
        if (null === self::$toMaster) {
            self::deliver($topic, $message); // decoded again: what subscribers get never depends on where it came from

            return;
        }
        if ('' !== $bitmap = self::bitmap('Swerve::publish', $topic)) {
            self::fanOut($topic, $message, $bitmap);
        }
    }

    /**
     * The master's bitmap of the workers with a subscription on the topic, kept until the master
     * says to forget it: '' for none. Needs a master, and a worker that serves.
     */
    public static function bitmap(string $caller, string $topic): string
    {
        if (!Cache::$listening) {
            throw new \LogicException("$caller() is there once the worker serves, not while swerve.php loads");
        }

        return self::$bitmaps->get($topic) ?? self::fetch($topic);
    }

    /** A JSON message to every worker in $bitmap, this one included. */
    public static function fanOut(string $topic, string $message, string $bitmap): void
    {
        $length   = \strlen($bitmap);
        $datagram = \chr(\strlen($topic)) . $topic . $message;
        for ($byte = 0; $byte < $length; ++$byte) {
            if (0 === $bits = \ord($bitmap[$byte])) {
                continue;
            }
            for ($bit = 0; $bit < 8; ++$bit) {
                if ($bits & (1 << $bit)) {
                    $inbox = ($byte << 3) | $bit;
                    if ($inbox === self::$own) {
                        self::deliver($topic, $message);
                    } else {
                        self::send($inbox, $datagram);
                    }
                }
            }
        }
    }

    /**
     * A datagram from this worker's inbox: a message for this process's subscribers of its topic.
     */
    public static function receive(string $datagram): void
    {
        $length = \ord($datagram[0]);
        self::deliver(\substr($datagram, 1, $length), \substr($datagram, 1 + $length));
    }

    /**
     * A JSON message for this process's subscribers of $topic, if any: decoded here, once for
     * all of them. They share the value (an array is copied only if one changes it; an object
     * is a SealedObject, which nobody can change). Each message carries when it arrived (now,
     * unless $arrived says), for Subscription's lag check.
     */
    public static function deliver(string $topic, string $message, ?int $arrived = null): void
    {
        if (isset(self::$writers[$topic])) {
            self::$writers[$topic]->write([$arrived ?? \hrtime(true), SealedObject::seal(\json_decode($message, false, 512, \JSON_THROW_ON_ERROR))]);
        }
    }

    /** Whether this process has a subscription on the topic. */
    public static function has(string $topic): bool
    {
        return isset(self::$counts[$topic]);
    }

    /**
     * A subscription starts: its topic's publisher is created with the first one. The subscriber
     * is attached before the master is waited for, so that nothing from then on is missed. Once
     * the master has the subscription on record, no message published from then on is missed by
     * the subscribers of other workers; a worker that does not serve yet can't be answered, and
     * has to be trusted.
     */
    public static function subscribe(string $topic): SubscriberInterface
    {
        if (!isset(self::$subscribers[$topic])) {
            phasync::publisher(self::$subscribers[$topic], self::$writers[$topic]);
            self::$counts[$topic] = 0;
        }
        ++self::$counts[$topic];
        $subscriber = self::$subscribers[$topic]->subscribe();
        if (null === self::$toMaster) {
            return $subscriber;
        }
        if (1 === self::$counts[$topic] && $ack = self::ask('+', $topic)) {
            self::$acks[$topic] = $ack;
        }
        if ($ack = self::$acks[$topic] ?? null) {
            try {
                while (!isset($ack->bitmap)) {
                    phasync::awaitFlag($ack);
                }
            } catch (\Throwable $e) {
                self::leave($topic); // the constructor failed: nothing else will
                throw $e;
            }
        }

        return $subscriber;
    }

    /** The master's reply to a request, see ask(): in the order of its messages. */
    public static function answer(string $message): void
    {
        $id     = \unpack('N', $message)[1];
        $waiter = self::$waiting[$id];
        unset(self::$waiting[$id]);
        if ((self::$acks[$waiter->topic] ?? null) === $waiter) {
            unset(self::$acks[$waiter->topic]);
        }
        $waiter->bitmap = \substr($message, 4);
        self::$bitmaps->set($waiter->topic, $waiter->bitmap);
        phasync::raiseFlag($waiter);
    }

    /** The master says a topic's bitmap changed. */
    public static function forget(string $topic): void
    {
        self::$bitmaps->delete($topic);
    }

    /**
     * A subscription ended. After the last one the topic is forgotten first, so that a
     * subscription starting meanwhile gets a publisher of its own, then its publisher is closed,
     * which ends its service coroutine, and the master is told.
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
        if (null !== self::$toMaster) {
            self::ask('-', $topic);
        }
    }

    /**
     * Ask the master: `g` for a topic's bitmap, `+` or `-` to subscribe or unsubscribe this
     * worker. Returns what to wait for, null when no reply comes: always for `-`, and for a
     * worker that does not read its pipe yet.
     */
    private static function ask(string $op, string $topic): ?\stdClass
    {
        $id     = 0;
        $waiter = null;
        if ('-' !== $op && Cache::$listening) {
            $id            = ++self::$next;
            $waiter        = self::$waiting[$id] = new \stdClass();
            $waiter->topic = $topic;
        }
        (self::$toMaster)(Inboxes::TOPIC, \pack('N', $id) . $op . $topic);

        return $waiter;
    }

    private static function fetch(string $topic): string
    {
        $waiter = self::ask('g', $topic);
        while (!isset($waiter->bitmap)) {
            phasync::awaitFlag($waiter);
        }

        return $waiter->bitmap;
    }

    /**
     * Write a datagram to a worker's inbox. A full inbox (the worker is busy) is waited for by
     * a coroutine per inbox, which keeps the datagrams in order; the caller never waits.
     */
    private static function send(int $inbox, string $datagram): void
    {
        if (isset(self::$outbox[$inbox])) {
            self::$outbox[$inbox]->enqueue($datagram);

            return;
        }
        if (\strlen($datagram) === @\stream_socket_sendto(self::$peers[$inbox], $datagram)) {
            unset(self::$dropped[$inbox]);

            return;
        }
        \error_clear_last(); // the inbox is full
        self::$outbox[$inbox] = new \SplQueue();
        self::$outbox[$inbox]->enqueue($datagram);
        phasync::go(self::flush(...), [$inbox]);
    }

    private static function flush(int $inbox): void
    {
        $queue  = self::$outbox[$inbox];
        $stream = self::$peers[$inbox];
        try {
            while (!$queue->isEmpty()) {
                try {
                    phasync::writable($stream, self::SEND_WAIT);
                } catch (TimeoutException) {
                    self::$logger->warning('Worker inbox {inbox} is not read: dropping {n} published messages for it', ['inbox' => $inbox, 'n' => \count($queue)]);
                    self::$dropped[$inbox] = true;

                    return;
                }
                while (!$queue->isEmpty() && \strlen($queue->bottom()) === @\stream_socket_sendto($stream, $queue->bottom())) {
                    $queue->dequeue();
                    unset(self::$dropped[$inbox]);
                }
                \error_clear_last();
            }
        } finally {
            unset(self::$outbox[$inbox]);
        }
    }

    /**
     * A message as it goes over the pipes between master and workers, among the single status
     * bytes (see Cluster): the requests of the cache and of the subscriptions, and the master's
     * replies. 'J' for a JSON message or 'P' for a raw one, the topic's length (1 byte), the
     * message's (4 bytes, big endian), when it was sent (hrtime(), 8 bytes: the machine's
     * monotonic clock, the same in every process), the topic, the message.
     */
    public static function frame(string $topic, string $message, bool $json = false): string
    {
        return ($json ? 'J' : 'P') . \chr(\strlen($topic)) . \pack('NJ', \strlen($message), \hrtime(true)) . $topic . $message;
    }

    /**
     * Take the complete frames and status bytes off the front of $buffer: calls $message for
     * each frame, with the frame itself last, and returns the status bytes. An incomplete frame
     * stays in $buffer for the next read.
     *
     * @param Closure(string $topic, string $message, string $frame, bool $json, int $published): void $message
     */
    public static function parse(string &$buffer, Closure $message): string
    {
        $status = '';
        $at     = 0;
        $length = \strlen($buffer);
        while ($at < $length) {
            if ('P' !== $buffer[$at] && 'J' !== $buffer[$at]) {
                $status .= $buffer[$at++];
                continue;
            }
            if ($length - $at < 14) {
                break;
            }
            $topicLength           = \ord($buffer[$at + 1]);
            ['l' => $messageLength, 'p' => $published] = \unpack('Nl/Jp', $buffer, $at + 2);
            $frameLength           = 14 + $topicLength + $messageLength;
            if ($length - $at < $frameLength) {
                break;
            }
            $message(\substr($buffer, $at + 14, $topicLength), \substr($buffer, $at + 14 + $topicLength, $messageLength), \substr($buffer, $at, $frameLength), 'J' === $buffer[$at], $published);
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
