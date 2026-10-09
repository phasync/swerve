<?php

namespace Swerve\Util;

/**
 * The autoloader of a swerve installed into an application's own vendor directory: it loads
 * swerve's own dependency closure, never the application's.
 *
 * Including the application's `vendor/autoload.php` in the worker would declare its classes and
 * run its Composer `files` (framework bootstraps such as CakePHP's ORM bootstrap.php) once, at
 * boot, so that their statics are shared by every request. Instead, this loader serves only the
 * packages reachable through `require` from swerve and the installed adapters (and the root
 * package when it is an adapter itself): their classes, from Composer's generated maps, and
 * their `files`. The application's own `require 'vendor/autoload.php'` then runs Composer's real
 * loader fresh inside each request, as under php-fpm. The closure's classes (swerve's,
 * phasync's, the PSR interfaces) are declared once and shared, which they are meant to be.
 *
 * Used only when swerve is itself among the packages of that vendor directory; otherwise swerve
 * includes the autoloader it was given, as before.
 *
 * @internal
 */
final class WorkerAutoloader
{
    /** @var array<string, string> class => file */
    private static array $classMap = [];

    /** @var array<string, list<string>> PSR-4 prefix => directories */
    private static array $psr4 = [];

    /** @var array<string, list<string>> PSR-0 prefix => directories */
    private static array $psr0 = [];

    /** @var list<string> path prefixes of the closure's packages */
    private static array $include = [];

    /** @var list<string> path prefixes excluded from those (the vendor directory, under a root package that is an adapter) */
    private static array $exclude = [];

    private static ?string $vendorDir = null;

    private function __construct()
    {
    }

    /**
     * Whether swerve itself is installed in `$vendorDir`: only then is this loader used.
     */
    public static function applies(string $vendorDir): bool
    {
        foreach (self::packages($vendorDir) as $package) {
            if ('phasync/swerve' === $package['name']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register the loader for swerve's closure in `$vendorDir`, and run the closure's `files`.
     *
     * @param string $rootDir the root package's directory (its composer.json may declare an adapter)
     */
    public static function register(string $vendorDir, string $rootDir): void
    {
        if (null !== self::$vendorDir) {
            throw new \LogicException('WorkerAutoloader::register() runs once, at startup');
        }
        self::$vendorDir = $vendorDir;
        $packages        = [];
        $providers       = [];
        foreach (self::packages($vendorDir) as $package) {
            $packages[$package['name']] = $package;
            foreach (\array_keys(($package['provide'] ?? []) + ($package['replace'] ?? [])) as $name) {
                $providers[$name][] = $package['name'];
            }
        }
        $queue = ['phasync/swerve'];
        foreach ($packages as $name => $package) {
            if (isset($package['extra']['swerve']['adapter'])) {
                $queue[] = $name;
            }
        }
        $root = \is_file("$rootDir/composer.json") ? \json_decode((string) \file_get_contents("$rootDir/composer.json"), true) : null;
        if (isset($root['extra']['swerve']['entry'])) {
            \array_push($queue, ...\array_keys($root['require'] ?? []));
            self::$include[] = self::normalize($rootDir) . '/';
            self::$exclude[] = self::normalize($vendorDir) . '/';
        }
        $seen = [];
        while (null !== ($name = \array_pop($queue))) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            foreach (isset($packages[$name]) ? [$name] : ($providers[$name] ?? []) as $found) {
                $package = $packages[$found];
                if (isset($package['install-path'])) {
                    self::$include[] = self::normalize("$vendorDir/composer/{$package['install-path']}") . '/';
                }
                \array_push($queue, ...\array_keys($package['require'] ?? []));
                $seen[$found] = true;
            }
        }
        self::refresh();
        \spl_autoload_register(self::load(...));
        foreach (require "$vendorDir/composer/autoload_files.php" as $id => $file) {
            if (self::inClosure($file) && empty($GLOBALS['__composer_autoload_files'][$id])) {
                $GLOBALS['__composer_autoload_files'][$id] = true;
                (static function () use ($file) { require $file; })();
            }
        }
    }

    /** Whether this loader is the worker's (see register()). */
    public static function isRegistered(): bool
    {
        return null !== self::$vendorDir;
    }

    /** Read Composer's generated maps again, as a worker does after the code changed. */
    public static function refresh(): void
    {
        $dir            = self::$vendorDir . '/composer';
        self::$classMap = \array_filter(require "$dir/autoload_classmap.php", self::inClosure(...));
        // Composer's own InstalledVersions, which phasync and swerve read
        if (\is_file("$dir/InstalledVersions.php")) {
            self::$classMap['Composer\InstalledVersions'] = "$dir/InstalledVersions.php";
        }
        foreach (['psr4' => 'autoload_psr4.php', 'psr0' => 'autoload_namespaces.php'] as $property => $file) {
            $map = [];
            foreach (\is_file("$dir/$file") ? require "$dir/$file" : [] as $prefix => $paths) {
                $paths = \array_values(\array_filter((array) $paths, self::inClosure(...)));
                if ([] !== $paths) {
                    $map[$prefix] = $paths;
                }
            }
            \krsort($map); // the longest prefix first
            self::$$property = $map;
        }
    }

    private static function load(string $class): void
    {
        $file = self::$classMap[$class] ?? null;
        if (null === $file) {
            $relative = \strtr($class, '\\', '/') . '.php';
            foreach (self::$psr4 as $prefix => $dirs) {
                if (\str_starts_with($class, $prefix)) {
                    foreach ($dirs as $dir) {
                        if (\is_file($candidate = $dir . '/' . \substr($relative, \strlen($prefix)))) {
                            $file = $candidate;
                            break 2;
                        }
                    }
                }
            }
            if (null === $file) {
                $relative = \strtr($relative, '_', '/');
                foreach (self::$psr0 as $prefix => $dirs) {
                    if (\str_starts_with($class, $prefix)) {
                        foreach ($dirs as $dir) {
                            if (\is_file($candidate = "$dir/$relative")) {
                                $file = $candidate;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        if (null !== $file) {
            (static function () use ($file) { require $file; })();
        }
    }

    private static function inClosure(string $path): bool
    {
        $path = self::normalize($path);
        foreach (self::$exclude as $prefix) {
            if (\str_starts_with($path, $prefix)) {
                foreach (self::$include as $included) {
                    if (\strlen($included) > \strlen($prefix) && \str_starts_with($path, $included)) {
                        return true;
                    }
                }

                return false;
            }
        }
        foreach (self::$include as $prefix) {
            if (\str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** `..` and `.` segments resolved by name, symlinks left alone (Composer's maps don't resolve them either). */
    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (\explode('/', $path) as $part) {
            if ('..' === $part) {
                \array_pop($parts);
            } elseif ('.' !== $part && '' !== $part) {
                $parts[] = $part;
            }
        }

        return '/' . \implode('/', $parts);
    }

    /** @return list<array<string, mixed>> */
    private static function packages(string $vendorDir): array
    {
        $file = "$vendorDir/composer/installed.json";
        if (!\is_file($file)) {
            return [];
        }
        $data = \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 is the list
        return $data['packages'] ?? $data;
    }
}
