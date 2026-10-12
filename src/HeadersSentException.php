<?php

namespace Swerve;

/**
 * The final response head was already committed: its status and headers can't change.
 *
 * @topic request-handlers
 */
final class HeadersSentException extends \LogicException
{
}
