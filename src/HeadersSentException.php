<?php

namespace Swerve;

/** The final response head was already committed: its status and headers can't change. */
final class HeadersSentException extends \LogicException
{
}
