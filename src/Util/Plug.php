<?php

namespace Swerve\Util;

/**
 * Installs phasync/plug's Loader in 'cache' mode when the phasync extension is not loaded, so
 * that application code calls phasync/hooked's cooperative replacements instead of blocking the
 * worker's event loop. Does nothing when the extension is loaded: every call in this class is
 * then a no-op, and {@see path()} returns its argument unchanged.
 *
 * Installed once, before the worker forks (see bin/swerve.php): the master's own autoloading of
 * the closure (WorkerAutoloader) runs before the fork too, and a file compiled natively, before
 * the loader exists, never becomes translated afterward (PHP compiles once; the loader works on
 * source, not bytecode) -- so installing here, first, covers every worker through the fork.
 *
 * @internal
 */
final class Plug
{
    private static bool $installed = false;

    private static ?string $cacheDir = null;

    private function __construct()
    {
    }

    /**
     * Installs phasync/plug's Loader, unless the phasync extension is already loaded or this
     * already ran. Loads phasync/babel's and phasync/plug's own classes directly, by their
     * Composer install paths -- never through an autoloader, which would itself need to be
     * loaded first, natively and so permanently untranslated (see phasync/plug's README, "cache
     * mode's bootstrap must never load Composer's own autoloader natively").
     *
     * @param string $vendorDir the vendor directory that has phasync/plug installed (babel and
     *                          hooked come along as its own dependencies, resolved the same way)
     * @param string $appRoot   the application's root directory, namespacing the shared on-disk
     *                          cache (several applications may share one machine's temp directory)
     *
     * @throws \RuntimeException when phasync/plug (or babel/hooked) is not installed in $vendorDir
     */
    public static function installIfNeeded(string $vendorDir, string $appRoot): void
    {
        if (\extension_loaded('phasync') || self::$installed) {
            return;
        }
        self::$installed = true;

        $installedFile = "$vendorDir/composer/installed.php";
        if (!\is_file($installedFile)) {
            throw new \RuntimeException("Swerve\\Util\\Plug: $installedFile not found; phasync/plug must be installed alongside swerve");
        }
        $versions = (require $installedFile)['versions'] ?? [];
        $pathOf   = static function (string $package) use ($versions): string {
            $path = $versions[$package]['install_path'] ?? null;
            if (null === $path) {
                throw new \RuntimeException("Swerve\\Util\\Plug: $package is not installed; require it alongside swerve (it is needed when ext-phasync is not loaded)");
            }

            return \rtrim($path, '/');
        };

        $babel = $pathOf('phasync/babel');
        $plug  = $pathOf('phasync/plug');
        $hooked = $pathOf('phasync/hooked');

        // Only the three classes Loader::install() itself needs, right now, natively: no
        // autoloader exists yet to find them. phasync/babel's shims (its own Composer "files")
        // load normally later, through whichever loader (WorkerAutoloader or Composer's own,
        // both now routed through path()) first reaches them -- requiring them here too would
        // run them twice: once now, unconditionally, and once more through that loader's own
        // dedup bookkeeping, which never learns about this first, separate require.
        require_once "$babel/src/Runtime.php";
        require_once "$babel/src/Unit.php";
        require_once "$babel/src/Babel.php";
        require_once "$plug/src/Loader.php";
        require_once "$plug/src/path.php";

        $map = require "$hooked/src/map.php";

        self::$cacheDir = \rtrim(\sys_get_temp_dir(), '/') . '/swerve-babel-' . \posix_getuid() . '/' . \hash('sha256', $appRoot);

        $swerve = $pathOf('phasync/swerve');
        \phasync\plug\Loader::install($map, self::$cacheDir, [
            $pathOf('phasync/phasync'),
            $pathOf('phasync/net'),
            $babel,
            $hooked,
            $plug,
            // Swerve's own code, never the whole install path: in this branch's own checkout
            // (running swerve's own test suite, or any path-repo symlink to it) that install
            // path is the repository root, which also holds the fixtures/examples under test --
            // excluding the whole thing would silently translate nothing of them, the same
            // mistake phasync/plug's README documents for its own src/ (see "What didn't work").
            "$swerve/src",
            "$swerve/bin",
        ], 'cache');
    }

    /** Whether installIfNeeded() installed the loader (false when ext-phasync is loaded). */
    public static function isInstalled(): bool
    {
        return self::$installed;
    }

    /** The on-disk cache directory in use, or null when not installed. */
    public static function cacheDir(): ?string
    {
        return self::$cacheDir;
    }

    /**
     * $path resolved and translated the way PHP's own include/require would from $fromDir, when
     * the loader is installed; $path unchanged otherwise. The one call every loader of
     * application code (swerve.php itself, an adapter's entry, WorkerAutoloader's own includes)
     * must use instead of require($path)/include($path) directly.
     */
    public static function path(string $path, string $fromDir): string
    {
        return self::$installed ? \phasync\plug\path($path, $fromDir) : $path;
    }
}
