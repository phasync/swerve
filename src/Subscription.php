<?php

namespace Swerve;

use phasync\SubscriberInterface;
use phasync\TimeoutException;
use Swerve\Util\Topics;

/**
 * Messages published to a topic, from Swerve::subscribe(): iterate over it to receive them.
 *
 * It receives what is published from the moment it was created, not from the first iteration.
 * It ends when its last reference goes: a `break` out of the loop, the variable going out of
 * scope, or the request's coroutine ending. There is no unsubscribe() to forget. Its iteration
 * ends when the process drains (shutdown, reload, recycle).
 *
 * A subscriber that falls behind gets a SubscriberLagException from the iteration: when the
 * message it is about to receive arrived in this process more than $maxLag seconds ago.
 * Messages wait for it meanwhile, shared with the topic's other subscribers.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Subscription implements \IteratorAggregate
{
    /** Null when made while the process drains: it has ended already. */
    private ?SubscriberInterface $subscriber = null;

    /**
     * @param float|null $heartbeat yield null when no message came for this many seconds
     */
    public function __construct(
        public readonly string $topic,
        public readonly float $maxLag = 30.0,
        public readonly ?float $heartbeat = null,
    ) {
        if (!Topics::$draining) {
            $this->subscriber = Topics::join($topic)->subscribe();
        }
    }

    /**
     * The messages, as they come; null after each $heartbeat seconds without one. Ends when the
     * process drains.
     *
     * @return \Generator<int, mixed> each message as published (a JSON message decoded; an object as a SealedObject), or null for a heartbeat
     *
     * @throws SubscriberLagException
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

    public function __destruct()
    {
        if (null !== $this->subscriber) {
            Topics::leave($this->topic);
        }
    }
}
