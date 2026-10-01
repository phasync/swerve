<?php

namespace Swerve;

use phasync\SubscriberInterface;
use phasync\TimeoutException;
use Swerve\Util\Topics;

/**
 * The messages published to a topic, from {@see Swerve::subscribe()}: iterate over it to receive them.
 *
 * It receives what is published from the moment it was created, not from the first iteration.
 * It ends when its last reference goes: a `break` out of the loop, the variable going out of
 * scope, or the request's coroutine ending. There is no unsubscribe() to forget. Its iteration
 * ends when the process drains (shutdown, reload, recycle).
 *
 * A subscriber that falls behind gets a {@see SubscriberLagException} from the iteration: when
 * the message it is about to receive arrived in this process more than `$maxLag` seconds ago.
 * Messages wait for it meanwhile, shared with the topic's other subscribers.
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
 * @see Swerve\OrderedChannel
 */
final class Subscription implements \IteratorAggregate
{
    /** Null when made while the process drains: it has ended already. */
    private ?SubscriberInterface $subscriber = null;

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
        if (!Topics::$draining) {
            $this->subscriber = Topics::subscribe($topic);
        }
    }

    /**
     * The messages, as they come; null after each `$heartbeat` seconds without one.
     *
     * Ends when the process drains. A subscription made while draining ends at once.
     *
     * ```php
     * foreach (Swerve::subscribe('chat') as $message) {
     *     handle($message);
     * }
     * ```
     *
     * @return \Generator<int, mixed> each message as published (a JSON message decoded; an object as a SealedObject), or null for a heartbeat
     *
     * @throws SubscriberLagException when the next message arrived more than `$maxLag` seconds ago
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
                return;
            }
            [$arrived, $message] = $item;
            $lag                 = (\hrtime(true) - $arrived) / 1e9;
            if ($lag > $this->maxLag) {
                throw new SubscriberLagException(\sprintf('A subscriber of "%s" fell %.1f s behind, more than its %.1f s', $this->topic, $lag, $this->maxLag));
            }
            yield $message;
        }
    }

    /** Ends the subscription: the topic stops keeping messages for it. */
    public function __destruct()
    {
        if (null !== $this->subscriber) {
            Topics::leave($this->topic);
        }
    }
}
