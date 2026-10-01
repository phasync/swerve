<?php

namespace Swerve;

/**
 * A closed connection or stream.
 *
 * Nothing in swerve throws it at present; it is a `\RuntimeException`.
 *
 * @see Swerve\ProtocolErrorException
 */
class ClosedException extends \RuntimeException
{
}
