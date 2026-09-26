<?php

namespace Swerve;

/**
 * A subscription fell further behind its topic than its maxLag, see Subscription.
 */
class SubscriberLagException extends \RuntimeException
{
}
