<?php

namespace Swerve;

/**
 * A key {@see Swerve::cache()} can't take: PSR-16 keys are non-empty strings without `{}()/\@:`.
 *
 * It is a `\InvalidArgumentException` and a PSR-16 `InvalidArgumentException`.
 *
 * ```php
 * try {
 *     Swerve::cache()->get('user:1');          // ':' is not allowed in a key
 * } catch (CacheKeyException $e) {
 *     Swerve::cache()->get('user.1');
 * }
 * ```
 *
 * @see Swerve\Cache
 */
final class CacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
