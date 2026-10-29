<?php

namespace Swerve;

use phasync\TimeoutException;
use Swerve\Util\Inbox;
use Swerve\Util\Topics;

/**
 * The messages published to a topic, from {@see Swerve::subscribe()}: iterate over it to receive them.
 *
 * It receives what is published from the moment it was created, not from the first iteration.
 * It ends when its last reference goes: a `break` out of the loop, the variable going out of
 * scope, or the request's coroutine ending. There is no unsubscribe() to forget. When the worker
 * stops (shutdown, reload, the end of a recycle), its iteration throws a
 * {@see WorkerStoppingException} at its wait, as every wait does then.
 *
 * A subscriber that falls behind gets a {@see SubscriberLagException} from the iteration: when
 * the message it is about to receive was published more than `$maxLag` seconds ago, or where
 * messages were lost (its worker fell a whole `--pubsub-ring` behind). Messages wait for it
 * meanwhile, shared with the topic's other subscribers.
 *
 * ```php
 * $subscription = Swerve::subscribe('chat', heartbeat: 15);   // subscribed from here
 * foreach ($subscription as $message) {
 *     if (null === $message) {
 *         continue;                      // 15 s without a message
 *     }
 *     $ws->send(json_encode($message));
 * }
 * ```
 *
 * @implements \IteratorAggregate<int, mixed>
 *
 * @see Swerve::subscribe
 * @see Swerve::publish
 *
 * @topic coordination-across-workers
 *
 * @example examples/Subscription/1-ends-with-reference.php A subscription ends with its last reference
 */
final class Subscription implements \IteratorAggregate
{
    /** Null when made while the process drains: it has ended already. */
    private ?Inbox $subscriber = null;

    /** The sequence number of the last message published before it: the earlier ones are not its. */
    private int $after = 0;

    /**
     * Subscribe to `$topic`: {@see Swerve::subscribe()} is how an application gets one.
     *
     * @param string     $topic     the topic to receive
     * @param float      $maxLag    seconds a message may wait before this subscriber reads it
     * @param float|null $heartbeat yield null when no message came for this many seconds
     */
    public function __construct(
        public readonly string $topic,
        public readonly float $maxLag = 30.0,
        public readonly ?float $heartbeat = null,
    ) {
        if (!Topics::$closed) {
            [$this->subscriber, $this->after] = Topics::subscribe($topic);
        }
    }

    /**
     * The messages, as they come; null after each `$heartbeat` seconds without one.
     *
     * Throws a {@see WorkerStoppingException} when the worker stops. A subscription made while
     * draining ends at once.
     *
     * ```php
     * foreach (Swerve::subscribe('chat') as $message) {
     *     handle($message);
     * }
     * ```
     *
     * @return \Generator<int, mixed> each message as published (an object a copy of its own), or null for a heartbeat
     *
     * @throws SubscriberLagException when the next message was published more than `$maxLag` seconds ago, or messages were lost
     */
    public function getIterator(): \Generator
    {
        while (null !== $this->subscriber) {
            try {
                $item = $this->subscriber->read($this->heartbeat ?? \PHP_FLOAT_MAX, $eof);
            } catch (TimeoutException) {
                yield null;
                continue;
            }
            if ($eof) {
                // Its topic ends only as the worker stops: the loop says so, as its wait would
                throw new WorkerStoppingException(Topics::$stopping);
            }
            [$seq, $published, $message, $copy] = $item;
            if ($seq <= $this->after) {
                continue; // published before this subscription; its worker reads on for older ones
            }
            if (Topics::lost() === $message) {
                throw new SubscriberLagException(\sprintf('A subscriber of "%s" lost messages: its worker fell a whole --pubsub-ring behind', $this->topic));
            }
            $lag = (\hrtime(true) - $published) / 1e9;
            if ($lag > $this->maxLag) {
                throw new SubscriberLagException(\sprintf('A subscriber of "%s" fell %.1f s behind, more than its %.1f s', $this->topic, $lag, $this->maxLag));
            }
            yield null === $copy ? $message : Topics::unserialize($copy); // with objects: a copy of its own
        }
    }

    /** Ends the subscription: the topic stops keeping messages for it. */
    public function __destruct()
    {
        if (null !== $this->subscriber) {
            Topics::leave($this->topic, $this->subscriber);
        }
    }
}
