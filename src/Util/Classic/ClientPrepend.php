<?php

namespace Swerve\Util\Classic;

/**
 * The `-d` flag that prepends Swerve's client (classic-client.php) to every script of a Classic
 * application, in the request server's and php -S's processes. The application's own
 * auto_prepend_file, from php.ini, still runs: the client includes it, named in the environment
 * the processes inherit (SWERVE_APP_PREPEND). Read from the ini files themselves, as Swerve's own
 * processes override auto_prepend_file on their command line.
 *
 * @internal
 */
final class ClientPrepend
{
    /** @return list<string> */
    public static function flags(): array
    {
        $prepend = '';
        foreach (\array_filter([\php_ini_loaded_file(), ...\explode(',', (string) \php_ini_scanned_files())]) as $file) {
            $prepend = (string) ((\parse_ini_file(\trim($file), false, \INI_SCANNER_RAW) ?: [])['auto_prepend_file'] ?? $prepend);
        }
        \putenv("SWERVE_APP_PREPEND=$prepend");

        return ['-d', 'auto_prepend_file=' . __DIR__ . '/classic-client.php'];
    }
}
