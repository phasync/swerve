<?php
namespace Swerve\Util;

use Exception;

/**
 * Operating system helpers.
 *
 * @internal
 */
final class System {

    /**
     * A new directory (mode 0700, a random name starting with $prefix) below $SWERVE_TMPDIR, or
     * the system's temporary directory. Created by the master before it forks, so that the
     * workers inherit it; removed, with the files in it, when the creating process exits: a
     * forked worker never removes it. $removed is called after that.
     */
    public static function tempDirectory(string $prefix, ?\Closure $removed = null): string
    {
        $directory = (\getenv('SWERVE_TMPDIR') ?: \sys_get_temp_dir()) . "/$prefix-" . \bin2hex(\random_bytes(8));
        \mkdir($directory, 0700);
        $creator = \getmypid();
        if (!self::$cleanups) {
            \register_shutdown_function(self::cleanup(...));
        }
        self::$cleanups[] = static function () use ($directory, $creator, $removed) {
            if (\getmypid() === $creator) {
                foreach (\glob("$directory/*") as $file) {
                    \unlink($file);
                }
                \rmdir($directory);
                $removed && $removed();
            }
        };

        return $directory;
    }

    /** @var list<\Closure> removing the temporary directories this process created */
    private static array $cleanups = [];

    /**
     * Remove the temporary directories this process created: at exit, or before the master
     * replaces itself for a reload, when no shutdown function runs.
     */
    public static function cleanup(): void
    {
        foreach (self::$cleanups as $cleanup) {
            $cleanup();
        }
        self::$cleanups = [];
    }

    public static function getCPUCount(): int {
        self::assertProcFS('/proc/cpuinfo');
        $c = \file_get_contents('/proc/cpuinfo');
        return \substr_count($c, 'processor');
    }

    /**
     * The memory this process may use, in bytes: the machine's, or its cgroup's limit when lower
     * (a container's), as Node.js sizes its heap from.
     */
    public static function getMemorySize(): int
    {
        self::assertProcFS('/proc/meminfo');
        \preg_match('/^MemTotal:\s+(\d+) kB/m', \file_get_contents('/proc/meminfo'), $m);
        $size = (int) $m[1] * 1024;
        if (\preg_match('/^0::(\S+)$/m', (string) @\file_get_contents('/proc/self/cgroup'), $m)) {
            $max = @\file_get_contents("/sys/fs/cgroup{$m[1]}/memory.max");
            if (false !== $max && \ctype_digit(\trim($max))) {
                $size = \min($size, (int) $max);
            }
        }

        return $size;
    }

    /**
     * Whether ext-sockets can be used (and not disabled): connections are then accepted, read and
     * written with its functions, which are a little faster than PHP's streams; otherwise
     * with streams alone.
     */
    public static function hasSockets(): bool
    {
        return \function_exists('socket_create') && \defined('SOCK_CLOEXEC');
    }

    /**
     * A connected pair of Unix sockets, close-on-exec where possible, see listen(): a process
     * the application starts must not hold the worker's end of its pipe to the master.
     *
     * @return array{0: resource, 1: resource}
     */
    public static function socketPair(int $type = \SOCK_STREAM): array
    {
        if (\defined('SOCK_CLOEXEC')) {
            \socket_create_pair(\AF_UNIX, $type | \SOCK_CLOEXEC, 0, $pair);

            return [\socket_export_stream($pair[0]), \socket_export_stream($pair[1])];
        }

        return \stream_socket_pair(\STREAM_PF_UNIX, \SOCK_DGRAM === $type ? \STREAM_SOCK_DGRAM : \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
    }

    /**
     * The name of a signal, such as SIGTERM, or SIG? for one PHP has no constant for.
     */
    public static function signalName(int $signal): string
    {
        foreach (['SIGHUP', 'SIGINT', 'SIGQUIT', 'SIGILL', 'SIGTRAP', 'SIGABRT', 'SIGBUS', 'SIGFPE', 'SIGKILL', 'SIGUSR1', 'SIGSEGV', 'SIGUSR2', 'SIGPIPE', 'SIGALRM', 'SIGTERM', 'SIGSTKFLT', 'SIGCHLD', 'SIGCONT', 'SIGSTOP', 'SIGTSTP', 'SIGTTIN', 'SIGTTOU', 'SIGURG', 'SIGXCPU', 'SIGXFSZ', 'SIGVTALRM', 'SIGPROF', 'SIGWINCH', 'SIGIO', 'SIGPWR', 'SIGSYS'] as $name) {
            if (\defined($name) && \constant($name) === $signal) {
                return $name;
            }
        }

        return 'SIG?';
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