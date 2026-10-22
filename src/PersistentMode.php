<?php

namespace Swerve;

/**
 * Persistent mode: the application boots once per worker, then its handler serves one request
 * at a time. State between requests is kept (statics, services), as with Laravel Octane,
 * RoadRunner or FrankenPHP's worker mode.
 *
 * ```php
 * // swerve.php
 * return PersistentMode::serve('public', require __DIR__ . '/bootstrap/swerve.php');
 * ```
 *
 * Files under the public root are served first (null: none); everything else goes to the handler,
 * which an {@see Adapter} runs (see {@see Handlers}).
 *
 * @topic modes
 */
final class PersistentMode extends Mode
{
    /**
     * @param string|null $publicRoot the directory served; null: no static files
     * @param object      $handler    what an {@see Adapter} runs: see {@see Handlers}
     *
     * @throws \InvalidArgumentException when the public root is not a directory
     */
    public function __construct(?string $publicRoot, public readonly object $handler)
    {
        parent::__construct($publicRoot);
    }

    /**
     * The same as `new PersistentMode(...)`, for chaining before PHP 8.4.
     *
     * @throws \InvalidArgumentException when the public root is not a directory
     */
    public static function serve(?string $publicRoot, object $handler): self
    {
        return new self($publicRoot, $handler);
    }
}
