<?php

/*
 * Swerve::cache() of a Classic script (see classic-client.php), declared at its first use: with the
 * PSR-16 interfaces when the application has psr/simple-cache, which is only known once its own
 * autoloader runs, after the prepended client.
 */

namespace Swerve\Classic;

if (\interface_exists(\Psr\SimpleCache\CacheInterface::class)) {
    final class Cache implements \Psr\SimpleCache\CacheInterface
    {
        use CacheMethods;
    }

    final class CacheKeyException extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
    {
    }
} else {
    final class Cache
    {
        use CacheMethods;
    }

    final class CacheKeyException extends \InvalidArgumentException
    {
    }
}
