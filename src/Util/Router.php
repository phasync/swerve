<?php

namespace Swerve\Util;

/**
 * Where a request goes, for {@see \Swerve\Swerve::accept()}: nginx's `try_files $uri $uri/`.
 *
 * 1. an existing file under the public root that isn't PHP source: served;
 * 2. a directory with a trailing slash: its `index.html`; without the slash, a 301 to it when
 *    it has one;
 * 3. everything else: the application (accept()'s handler).
 *
 * The path is decoded and normalised first (dot segments resolved, repeated slashes merged);
 * control characters, invalid UTF-8 or a `..` above the root are a bad request. Never served:
 * a path with a segment starting with a dot (but `/.well-known/`), PHP source, anything
 * resolving outside the public root (symlinks included).
 *
 * @internal
 */
final class Router
{
    public const BAD      = 0;
    public const STATIC   = 1;
    public const REDIRECT = 2;
    public const APP      = 3;

    /** PHP source: never served as a file. */
    private const SOURCE = '/\.(?:php\d?|phps|phtml|phar|inc)$/i';

    public function __construct(private readonly ?string $root)
    {
    }

    /**
     * The route for a request target.
     *
     * [BAD]; [STATIC, the file]; [REDIRECT, the location]; [APP].
     *
     * @return array{0: int, 1?: string}
     */
    public function route(string $target): array
    {
        $q    = \strpos($target, '?');
        $path = false === $q ? $target : \substr($target, 0, $q);
        if ('/' !== ($path[0] ?? '')) {
            // The absolute form (a proxy request): its path. '*' (OPTIONS) is the application's.
            if (!\preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#Di', $path, $m)) {
                return '*' === $target ? [self::APP] : [self::BAD];
            }
            $path = $m[1] ?? '/';
        }
        $path = self::normalise(\rawurldecode($path));
        if (null === $path) {
            return [self::BAD];
        }
        if (null === $this->root || \preg_match('#/\.(?!well-known(?:/|$))#', $path)) {
            return [self::APP];
        }

        $file = $this->root . $path;
        if (\is_file($file)) {
            if (!$this->inside($file) || \preg_match(self::SOURCE, $path)) {
                return [self::APP];
            }

            return [self::STATIC, \realpath($file)];
        }
        if (\is_dir($file)) {
            if (!$this->inside($file)) {
                return [self::APP];
            }
            $dir = \rtrim($file, '/');
            if (!\is_file("$dir/index.html")) {
                return [self::APP];
            }
            if (!\str_ends_with($path, '/')) {
                return [self::REDIRECT, \implode('/', \array_map(\rawurlencode(...), \explode('/', $path))) . '/' . (false === $q ? '' : \substr($target, $q))];
            }

            return [self::STATIC, \realpath("$dir/index.html")];
        }

        return [self::APP];
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
