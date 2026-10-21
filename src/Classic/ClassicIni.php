<?php

namespace Swerve\Classic;

/**
 * The `-d` flags given to every `php -S` process of a {@see ClassicPool}, besides
 * `auto_prepend_file`: opcache settings chosen by measuring classic mode against FPM on
 * WordPress (see CLASSIC-RESULTS.md for the variants tried and why this one won), and
 * `output_buffering=0`, without which `php -S`'s own default buffering holds a script's
 * `flush()`ed output back until the script ends (verified directly: a script that echoes,
 * flushes, sleeps and echoes again sends nothing early unless this is off).
 *
 * @internal
 */
final class ClassicIni
{
    /** opcache on (incl. for the cli-server SAPI, which needs both switches), timestamps not checked. */
    private const DEFAULT = [
        '-d', 'zend_extension=opcache',
        '-d', 'opcache.enable=1',
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.memory_consumption=256',
        '-d', 'opcache.max_accelerated_files=50000',
        '-d', 'opcache.interned_strings_buffer=16',
        '-d', 'output_buffering=0',
    ];

    /**
     * The flags, in order: {@see ClassicIni::DEFAULT} unless the environment variable
     * `SWERVE_CLASSIC_INI` holds a JSON array of flags to use instead (benchmarking the variants
     * of CLASSIC-RESULTS.md without editing this file).
     *
     * @return list<string>
     */
    public static function flags(): array
    {
        $override = \getenv('SWERVE_CLASSIC_INI');
        if (false !== $override) {
            $decoded = \json_decode($override, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return self::DEFAULT;
    }

    /**
     * Whether {@see ClassicProxy} pre-connects the next request's socket right after a process
     * starts and right after it finishes a response: on by default, off only when the
     * environment variable `SWERVE_CLASSIC_PRECONNECT` is `0` (measuring its effect in
     * CLASSIC-RESULTS.md without editing this file).
     */
    public static function preconnect(): bool
    {
        return '0' !== \getenv('SWERVE_CLASSIC_PRECONNECT');
    }
}
