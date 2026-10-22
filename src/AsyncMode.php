<?php

namespace Swerve;

/**
 * Async mode: the application boots once per worker, and its requests are coroutines that
 * overlap while they wait (on databases, HTTP APIs, timers), as in Node.js. State between
 * requests is kept and shared, so per-request state belongs in the request or its `Scope`.
 *
 * ```php
 * // swerve.php
 * return AsyncMode::serve('public', $app);
 * ```
 *
 * Files under the public root are served first (null: none); everything else goes to the handler,
 * which an {@see Adapter} runs (see {@see Handlers}).
 *
 * @topic modes
 *
 * @example examples/AsyncMode/1-hello-world.php Hello, world
 */
final class AsyncMode extends Mode
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
     * The same as `new AsyncMode(...)`, for chaining before PHP 8.4.
     *
     * @throws \InvalidArgumentException when the public root is not a directory
     */
    public static function serve(?string $publicRoot, object $handler): self
    {
        return new self($publicRoot, $handler);
    }
}
