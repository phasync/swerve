<?php

namespace Swerve;

/**
 * A key Swerve::cache() can't take: PSR-16 keys are non-empty strings without {}()/\@:
 */
final class CacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
