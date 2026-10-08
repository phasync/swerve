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

    /** Close every unix: listener and remove its socket file, before the master replaces itself. */
    public static function closeListeners(): void
    {
        foreach (self::$unixListeners as $address => $listener) {
            \fclose($listener);
            self::unlinkSocket($address);
        }
        self::$unixListeners = [];
    }

    public static function getCPUCount(): int {
        self::assertProcFS('/proc/cpuinfo');
        $c = \file_get_contents('/proc/cpuinfo');
        return \substr_count($c, 'processor');
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
     * The listener of a `unix:/path` address: one socket for every worker, so the first call
     * (in the master) creates the socket file (refusing a path where anything listens,
     * replacing a stale one), and later calls, in the workers forked after it, return that same
     * listener. Anyone may connect to it; restrict it with the permissions of the directory it
     * is in. The file stays until unlinkSocket(). TCP addresses are listened on by
     * phasync\Net\Server, which every worker does itself (SO_REUSEPORT).
     *
     * @return resource non-blocking
     *
     * @throws \RuntimeException
     */
    public static function listen(string $address): mixed
    {
        return self::$unixListeners[$address] ??= self::listenUnix(\substr($address, 5));
    }

    /** @var array<string, resource> */
    private static array $unixListeners = [];

    /** @return resource */
    private static function listenUnix(string $path): mixed
    {
        if (\file_exists($path)) {
            if ('socket' !== \filetype($path)) {
                throw new \RuntimeException("Could not listen at $path: it exists, and is not a socket");
            }
            if ($probe = @\stream_socket_client("unix://$path", $errno, $errstr, 1)) {
                \fclose($probe);
                throw new \RuntimeException("Could not listen at $path: already in use");
            }
            \unlink($path);
        }
        if (self::hasSockets()) {
            $socket = \socket_create(\AF_UNIX, \SOCK_STREAM | \SOCK_CLOEXEC, 0);
            if (!@\socket_bind($socket, $path) || !@\socket_listen($socket, 65535)) {
                $errno = \socket_last_error($socket);
                throw new \RuntimeException("Could not listen at $path: " . \socket_strerror($errno), $errno);
            }
            $listener = \socket_export_stream($socket);
        } else {
            $listener = @\stream_socket_server("unix://$path", $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
            if (false === $listener) {
                throw new \RuntimeException("Could not listen at $path: $errstr", $errno);
            }
        }
        \chmod($path, 0666);
        \stream_set_blocking($listener, false);

        return $listener;
    }

    /** Remove the socket file of a unix: address from listen(), when the server stops. */
    public static function unlinkSocket(string $address): void
    {
        @\unlink(\substr($address, 5));
    }

    /**
     * Pin this process to the CPUs it may use on one NUMA node, taking the nodes in turn by
     * $index, and return those CPUs as a list; null when they lie on one node only (a machine
     * with one node, or an operator's taskset, cpuset or --cpuset-cpus within one), or when it
     * can't be done (then the process stays as it was: the same, only slower on a machine with
     * several sockets). Only the CPUs the process inherited are used: pinning never widens
     * them. Call right after fork, before the process allocates much: memory then comes from the
     * node's own, and threads started later (phasync-ext's) inherit the pinning.
     *
     * With several sockets, a scheduler free to move a worker between them takes it away from
     * the memory it allocated; pinned, swerve served about a quarter more at 10,000 connections
     * on a 2-socket machine (phasync/phasync#50).
     */
    public static function pinToNumaNode(int $index): ?string
    {
        $parse = static function (string $list): array {
            $cpus = [];
            foreach ('' === $list ? [] : \explode(',', $list) as $range) {
                [$first, $last] = \array_map('intval', \explode('-', $range) + [1 => $range]);
                for ($cpu = $first; $cpu <= $last; ++$cpu) {
                    $cpus[] = $cpu;
                }
            }

            return $cpus;
        };
        \preg_match('/^Cpus_allowed_list:\s*(\S+)/m', (string) @\file_get_contents('/proc/self/status'), $m);
        $allowed = $parse($m[1] ?? '');
        $nodes   = \glob('/sys/devices/system/node/node[0-9]*', \GLOB_ONLYDIR) ?: [];
        \natsort($nodes);
        $shares = [];
        foreach ($nodes as $node) {
            $cpus = \array_values(\array_intersect($parse(\trim((string) @\file_get_contents("$node/cpulist"))), $allowed));
            if ($cpus) {
                $shares[] = $cpus;
            }
        }
        if (\count($shares) < 2) {
            return null;
        }
        $share = $shares[$index % \count($shares)];
        $cpus  = \implode(',', $share);
        // FFI where it may be used (in the CLI by default): no process started
        if (\class_exists(\FFI::class, false)) {
            try {
                $libc = \FFI::cdef('int sched_setaffinity(int pid, size_t size, const unsigned char *mask);', 'libc.so.6');
                $mask = $libc->new('unsigned char[128]'); // cpu_set_t: 1024 CPUs
                foreach ($share as $cpu) {
                    $mask[$cpu >> 3] |= 1 << ($cpu & 7);
                }
                if (0 === $libc->sched_setaffinity(0, 128, $mask)) {
                    return $cpus;
                }
            } catch (\Throwable) {
                // FFI disabled (ffi.enable=0), or no libc.so.6 (musl): try taskset
            }
        }
        if (\function_exists('exec')) {
            \exec('taskset -a -cp ' . \escapeshellarg($cpus) . ' ' . \getmypid() . ' 2>/dev/null', $output, $status);
            if (0 === $status) {
                return $cpus;
            }
        }

        return null;
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