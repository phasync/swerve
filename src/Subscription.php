<?php

namespace Swerve;

use phasync\SubscriberInterface;
use Swerve\Util\Topics;

/**
 * Messages published to a topic, from Swerve::subscribe(): iterate over it to receive them.
 *
 * It receives what is published from the moment it was created, not from the first iteration.
 * It ends when its last reference goes: a `break` out of the loop, the variable going out of
 * scope, or the request's coroutine ending. There is no unsubscribe() to forget.
 *
 * A subscriber that falls behind gets a SubscriberLagException from the iteration: when the
 * message it is about to receive arrived in this process more than $maxLag seconds ago.
 * Messages wait for it meanwhile, shared with the topic's other subscribers.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class Subscription implements \IteratorAggregate
{
    private SubscriberInterface $subscriber;

    public function __construct(
        public readonly string $topic,
        public readonly float $maxLag = 30.0,
    ) {
        $this->subscriber = Topics::join($topic)->subscribe();
    }

    /**
     * @throws SubscriberLagException
     */
    public function getIterator(): \Generator
    {
        foreach ($this->subscriber as [$arrived, $message]) {
            $lag = (\hrtime(true) - $arrived) / 1e9;
            if ($lag > $this->maxLag) {
                throw new SubscriberLagException(\sprintf('A subscriber of "%s" fell %.1f s behind, more than its %.1f s', $this->topic, $lag, $this->maxLag));
            }
            yield $message;
        }
    }

    public function __destruct()
    {
        Topics::leave($this->topic);
    }
}
