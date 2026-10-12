<?php

namespace Swerve;

/**
 * A subscription fell further behind its topic than its `$maxLag` seconds, see {@see Subscription}.
 *
 * Thrown from the subscription's loop, which ends. A client that can't keep up is better
 * disconnected: it reconnects and catches up from storage. An {@see OrderedChannel} subscription
 * also throws it when messages were lost because its worker did not run for about 30 s.
 *
 * ```php
 * try {
 *     foreach (Swerve::subscribe('chat', maxLag: 5.0) as $message) {
 *         $ws->send(json_encode($message));
 *     }
 * } catch (SubscriberLagException) {
 *     $ws->end(1013, 'too slow');
 * }
 * ```
 *
 * @see Swerve::subscribe
 * @see Swerve\Subscription
 *
 * @topic coordination-across-workers
 *
 * @example examples/SubscriberLagException/1-too-slow.php A subscriber that fell behind
 */
class SubscriberLagException extends \RuntimeException
{
}
