<?php

namespace Swerve\Http;

/**
 * A request the server refuses: the code is the HTTP status, the message its reason phrase.
 * The connection is closed after the response.
 *
 * @internal
 */
final class HttpError extends \RuntimeException
{
}
