<?php

namespace Swerve\Util;

/**
 * Which adapter provides an application's entry point: swerve's own (`swerve.php`), or an installed package's.
 *
 * A package declares itself in its own composer.json:
 *
 * ```json
 * {"extra": {"swerve": {"adapter": "psr15", "entry": "Swerve\\Psr15\\init"}}}
 * ```
 *
 * `entry` names a function that each worker calls once, after the fork, with the application
 * directory, and that returns a {@see \Swerve\RequestHandler}. The master only reads
 * `vendor/composer/installed.json`: it loads no adapter code.
 *
 * @internal
 */
final class Adapters
{
    /** The adapter that loads the application's `swerve.php`. */
    public const BUILT_IN = 'swerve';

    private function __construct()
    {
    }

    /**
     * The adapters the packages in `$appDir/vendor` declare.
     *
     * @return array<string, string> the adapter's name to its entry function
     *
     * @throws \RuntimeException for a package declaring an adapter without an entry, a name twice, or the name `swerve`
     */
    public static function installed(string $appDir): array
    {
        $file = "$appDir/vendor/composer/installed.json";
        if (!\is_file($file)) {
            return [];
        }
        $data     = self::json($file);
        $adapters = [];
        $owners   = [self::BUILT_IN => 'swerve itself'];
        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 is the list
        foreach ($data['packages'] ?? $data as $package) {
            $declared = $package['extra']['swerve'] ?? null;
            if (!isset($declared['adapter'])) {
                continue;
            }
            $name = $declared['adapter'];
            if (!\is_string($declared['entry'] ?? null)) {
                throw new \RuntimeException("{$package['name']} declares the swerve adapter $name without an \"entry\" function");
            }
            if (isset($owners[$name])) {
                throw new \RuntimeException("{$package['name']} declares the swerve adapter $name, which {$owners[$name]} provides already");
            }
            $owners[$name]   = $package['name'];
            $adapters[$name] = $declared['entry'];
        }

        return $adapters;
    }

    /**
     * The adapter the application's own composer.json names, if it does.
     */
    public static function configured(string $appDir): ?string
    {
        $file = "$appDir/composer.json";

        return \is_file($file) ? (self::json($file)['extra']['swerve']['adapter'] ?? null) : null;
    }

    /**
     * Choose the adapter: `--adapter`, else the application's composer.json, else the only installed one, else `swerve`.
     *
     * @param array<string, string> $installed see {@see installed()}
     * @param string|null           $option    `--adapter`
     * @param string|null           $configured see {@see configured()}
     *
     * @throws \RuntimeException for an adapter that is not installed, or several installed and none chosen
     */
    public static function select(array $installed, ?string $option, ?string $configured): string
    {
        $name = $option ?? $configured;
        if (null !== $name) {
            if (self::BUILT_IN !== $name && !isset($installed[$name])) {
                throw new \RuntimeException("The adapter $name is not installed" . ($installed ? ' (installed: ' . \implode(', ', \array_keys($installed)) . ')' : ''));
            }

            return $name;
        }
        if (\count($installed) > 1) {
            throw new \RuntimeException('Several adapters are installed (' . \implode(', ', \array_keys($installed)) . ') and none is chosen: '
                . 'pass --adapter=<name>, or set "extra": {"swerve": {"adapter": "<name>"}} in the application\'s composer.json');
        }

        return \array_key_first($installed) ?? self::BUILT_IN;
    }

    private static function json(string $file): array
    {
        try {
            return \json_decode((string) \file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("$file is not valid JSON: {$e->getMessage()}");
        }
    }
}
