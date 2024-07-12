<?php
namespace Swerve\Util;

use Exception;

final class System {

    public static function getCPUCount(): int {
        self::assertProcFS('/proc/cpuinfo');
        $c = \file_get_contents('/proc/cpuinfo');
        return \substr_count($c, 'processor');
    }

    private static function assertProcFS(?string $file=null): void {
        if (\PHP_OS_FAMILY === 'Windows') {
            throw new Exception("Swerve does not work on Windows currently. Try WSL.");
        }
        if (!\is_dir('/proc')) {
            throw new Exception("/proc must be available");
        }
        if ($file !== null && !\file_exists($file)) {
            throw new Exception("$file not accessible");
        }
    }

}