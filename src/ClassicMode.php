<?php

namespace Swerve;

/**
 * Classic mode: every request runs the application's scripts from scratch, with PHP's own
 * request lifecycle, as under PHP-FPM. Existing applications such as WordPress run unmodified.
 *
 * Routing is nginx's `try_files $uri $uri/ /index.php`: a file under the public root is served
 * (a `.php` file runs, also with a path after it as `PATH_INFO`); a directory requested with a
 * trailing slash runs its `index.php` or serves its `index.html`; everything else runs the
 * router.
 *
 * ```php
 * // swerve.php
 * return ClassicMode::serve('public');                    // public/index.php is the router
 * return ClassicMode::serve('public', 'app/front.php');   // a router outside the public root
 * ```
 *
 * The scripts run in separate processes: ext-phasync's request server, or a pool of `php -S`
 * without the extension. Code in `swerve.php` itself runs in Swerve's workers and affects only
 * the async routes (see {@see withAsync()}).
 *
 * @topic modes
 */
final class ClassicMode extends Mode
{
    /** The router script's real path. */
    public readonly string $router;

    /**
     * @param string      $publicRoot the directory served; '' is the working directory
     * @param string|null $router     the script for every path that doesn't exist; default `{$publicRoot}/index.php`
     *
     * @throws \InvalidArgumentException when the public root is not a directory, or the router not a file
     */
    public function __construct(string $publicRoot = '', ?string $router = null)
    {
        parent::__construct($publicRoot);
        $path = $router ?? $this->publicRoot . '/index.php';
        $real = \realpath($path);
        if (false === $real || !\is_file($real)) {
            throw new \InvalidArgumentException("The router $path is not a file" . (\str_starts_with($path, '/') ? '' : ' (relative to ' . \getcwd() . ')'));
        }
        $this->router = $real;
    }

    /**
     * The same as `new ClassicMode(...)`, for chaining before PHP 8.4.
     *
     * @throws \InvalidArgumentException when the public root is not a directory, or the router not a file
     */
    public static function serve(string $publicRoot = '', ?string $router = null): self
    {
        return new self($publicRoot, $router);
    }
}
