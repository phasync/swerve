<?php

namespace Swerve\Util;

use Swerve\ClassicMode;
use Swerve\Mode;

/**
 * Where a request goes, the same in every mode: nginx's `try_files $uri $uri/ /index.php`.
 *
 * 1. the first async route whose pattern matches the path;
 * 2. an existing file under the public root that isn't PHP source: served;
 * 3. Classic: an existing `.php` file runs, also followed by `/` and more, which is `PATH_INFO`;
 * 4. a directory with a trailing slash: its `index.php` (Classic) or `index.html`; without the
 *    slash, a 301 to it when it has one;
 * 5. everything else: the application (Classic: the router script).
 *
 * The path is decoded and normalised first (dot segments resolved, repeated slashes merged);
 * control characters, invalid UTF-8 or a `..` above the root are a bad request. Never served or
 * run: a path with a segment starting with a dot (but `/.well-known/`), PHP source, anything
 * resolving outside the public root.
 *
 * @internal
 */
final class Router
{
    public const BAD      = 0;
    public const ASYNC    = 1;
    public const STATIC   = 2;
    public const SCRIPT   = 3;
    public const REDIRECT = 4;
    public const APP      = 5;

    /** PHP source: never served as a file. Only `.php` runs (Classic). */
    private const SOURCE = '/\.(?:php\d?|phps|phtml|phar|inc)$/i';

    private readonly ?string $root;
    private readonly bool $classic;
    /** @var list<string> */
    private readonly array $patterns;
    /** The router script and its SCRIPT_NAME (Classic). */
    private readonly ?string $router;
    private readonly ?string $routerName;

    public function __construct(Mode $mode)
    {
        $this->root     = $mode->publicRoot;
        $this->classic  = $mode instanceof ClassicMode;
        $this->patterns = \array_map(static fn (array $route) => $route[1], $mode->asyncRoutes());
        if ($mode instanceof ClassicMode) {
            $this->router     = $mode->router;
            $this->routerName = \str_starts_with($mode->router, $this->root . '/') ? \substr($mode->router, \strlen($this->root)) : '/' . \basename($mode->router);
        } else {
            $this->router     = null;
            $this->routerName = null;
        }
    }

    /**
     * The route for a request target.
     *
     * [BAD]; [ASYNC, the route's index]; [STATIC, the file]; [REDIRECT, the location];
     * [SCRIPT, SCRIPT_FILENAME, SCRIPT_NAME, PATH_INFO or null]; [APP].
     *
     * @return array{0: int, 1?: int|string, 2?: string, 3?: string|null}
     */
    public function route(string $target): array
    {
        $q    = \strpos($target, '?');
        $path = false === $q ? $target : \substr($target, 0, $q);
        if ('/' !== ($path[0] ?? '')) {
            // The absolute form (a proxy request): its path. '*' (OPTIONS) is the application's.
            if (!\preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#Di', $path, $m)) {
                return '*' === $target ? $this->app() : [self::BAD];
            }
            $path = $m[1] ?? '/';
        }
        $path = self::normalise(\rawurldecode($path));
        if (null === $path) {
            return [self::BAD];
        }
        foreach ($this->patterns as $i => $regex) {
            if (\preg_match($regex, $path)) {
                return [self::ASYNC, $i];
            }
        }
        if (null === $this->root || \preg_match('#/\.(?!well-known(?:/|$))#', $path)) {
            return $this->app();
        }

        $file = $this->root . $path;
        if (\is_file($file)) {
            if (!$this->inside($file)) {
                return $this->app();
            }
            if (\preg_match(self::SOURCE, $path)) {
                return $this->classic && \str_ends_with($path, '.php') ? [self::SCRIPT, \realpath($file), $path, null] : $this->app();
            }

            return [self::STATIC, \realpath($file)];
        }
        if (\is_dir($file)) {
            if (!$this->inside($file)) {
                return $this->app();
            }
            $dir   = \rtrim($file, '/');
            $index = $this->classic && \is_file("$dir/index.php") ? 'index.php' : (\is_file("$dir/index.html") ? 'index.html' : null);
            if (null === $index) {
                return $this->app();
            }
            if (!\str_ends_with($path, '/')) {
                return [self::REDIRECT, \implode('/', \array_map(\rawurlencode(...), \explode('/', $path))) . '/' . (false === $q ? '' : \substr($target, $q))];
            }

            return 'index.php' === $index ? [self::SCRIPT, \realpath("$dir/index.php"), $path . 'index.php', null] : [self::STATIC, \realpath("$dir/index.html")];
        }
        // Classic: an existing .php file followed by a path, which is PATH_INFO
        if ($this->classic && false !== $at = \strpos($path, '.php/')) {
            $script = \substr($path, 0, $at + 4);
            $file   = $this->root . $script;
            if (\is_file($file) && $this->inside($file)) {
                return [self::SCRIPT, \realpath($file), $script, \substr($path, $at + 4)];
            }
        }

        return $this->app();
    }

    /** @return array{0: self::SCRIPT, 1: string, 2: string, 3: null}|array{0: self::APP} */
    private function app(): array
    {
        return $this->classic ? [self::SCRIPT, $this->router, $this->routerName, null] : [self::APP];
    }

    /** Whether a path that exists resolves (symlinks included) inside the public root. */
    private function inside(string $file): bool
    {
        \clearstatcache(true, $file);
        $real = \realpath($file);

        return false !== $real && ($real === $this->root || \str_starts_with($real, $this->root . '/'));
    }

    /**
     * The decoded path with dot segments resolved and repeated slashes merged, the trailing
     * slash kept; null for control characters, invalid UTF-8, or a `..` above the root.
     */
    private static function normalise(string $path): ?string
    {
        if (\preg_match('/[\x00-\x1F\x7F]/', $path) || !\preg_match('//u', $path)) {
            return null;
        }
        if (!\preg_match('#//|/\.\.?(?:/|$)#', $path)) {
            return $path; // nothing to do, the common case
        }
        $out = [];
        foreach (\explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if ([] === $out) {
                    return null;
                }
                \array_pop($out);
                continue;
            }
            $out[] = $segment;
        }
        $trailing = \preg_match('#/\.{0,2}$#', $path) && [] !== $out;

        return '/' . \implode('/', $out) . ($trailing ? '/' : '');
    }
}
