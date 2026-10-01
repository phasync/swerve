<?php

namespace Swerve;

use Swerve\Util\OrderedLog;

/**
 * A topic where every subscriber, in every worker, receives the messages in one and the same
 * order, and each publisher's own messages keep theirs:
 *
 *     $ledger = new OrderedChannel('ledger');
 *     $ledger->write(['debit', 12]);
 *     foreach ($ledger as $entry) { ... }
 *
 * Plain Swerve::publish() from one publisher is already ordered for its subscribers, which is
 * enough for a stream between two coroutines or workers. This is for many publishers that need
 * one common order.
 *
 * It is a broadcast, not a queue: nothing is written while nobody subscribes anywhere, and there
 * are no writers to end it or close it. Retention is bounded (about 30 s): a subscriber lagging
 * beyond it gets a SubscriberLagException. Each message costs one extra file append; a worker is
 * woken by a datagram, best effort, and looks at the log at least every second. It is separate from
 * the plain topic of the same name. See docs/publish-subscribe.md.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class OrderedChannel implements \IteratorAggregate
{
    public function __construct(public readonly string $name)
    {
    }

    /**
     * Append $message, as Swerve::publish() sends one: nothing is written, and the message is
     * dropped, while the channel has no subscriber anywhere.
     *
     * @throws \InvalidArgumentException as Swerve::publish() does, and for a name starting with "\0"
     * @throws \LogicException           while the application loads: the worker serves after that
     * @throws \JsonException            for a value JSON can't express
     */
    public function write(mixed $message): void
    {
        self::refuseInternal($this->name);
        OrderedLog::publish($this->name, $message);
    }

    /**
     * Receive what is written from now on, as Swerve::subscribe() does: the subscription exists
     * once this returns.
     *
     * @throws \InvalidArgumentException for a name of 0 or over 255 bytes, or starting with "\0"
     * @throws \LogicException           while the application loads: the worker serves after that
     */
    public function subscribe(float $maxLag = 30.0, ?float $heartbeat = null): Subscription
    {
        self::refuseInternal($this->name);

        return OrderedLog::subscribe($this->name, $maxLag, $heartbeat);
    }

    /** Subscribes with the defaults, so `foreach ($channel as $message)` subscribes at the foreach. */
    public function getIterator(): Subscription
    {
        return $this->subscribe();
    }

    private static function refuseInternal(string $name): void
    {
        if (\str_starts_with($name, "\0")) {
            throw new \InvalidArgumentException('Topics starting with "\\0" are swerve\'s own');
        }
    }
}
