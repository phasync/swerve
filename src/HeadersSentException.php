<?php

namespace Swerve;

/**
 * The final response head was already committed: its status and headers can't change.
 *
 * @topic request-handlers
 *
 * @example examples/HeadersSentException/1-head-is-out.php A status after the body began
 */
final class HeadersSentException extends \LogicException
{
}
