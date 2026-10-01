<?php

namespace Swerve;

/**
 * A peer broke its protocol.
 *
 * Thrown by the FastCGI server for a record the protocol does not allow, such as one for an
 * unknown request id or of an unknown type, and logged with the peer's address. It is a
 * `\RuntimeException`.
 *
 * @see Swerve\ClosedException
 */
class ProtocolErrorException extends \RuntimeException
{
}
