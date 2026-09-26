<?php
namespace Swerve\Util;

use Exception;

final class System {

    public static function getCPUCount(): int {
        self::assertProcFS('/proc/cpuinfo');
        $c = \file_get_contents('/proc/cpuinfo');
        return \substr_count($c, 'processor');
    }

    /**
     * Listen on a TCP address such as 127.0.0.1:8080 or [::1]:8080 (a tcp:// prefix is
     * allowed), with SO_REUSEPORT, as every worker does on the same address. Accepted
     * connections get TCP_NODELAY.
     *
     * The listener is close-on-exec, so processes the application starts (exec('job &'),
     * proc_open(), mail()) don't inherit it. An inherited listener stays in the SO_REUSEPORT
     * group after its worker died without draining (a crash, the watchdog's SIGKILL): the kernel
     * goes on giving it a share of the new connections, which nothing accepts, and it keeps the
     * address taken after swerve stopped. PHP's streams are never close-on-exec; the sockets
     * extension makes them so from PHP 8.4. Without it, the listener is inherited.
     *
     * @return resource non-blocking
     *
     * @throws \RuntimeException
     */
    public static function listen(string $address): mixed
    {
        $address = \preg_replace('#^tcp://#', '', $address);
        if (\defined('SOCK_CLOEXEC')) {
            \preg_match('/^\[?(.*?)\]?:(\d+)$/D', $address, $m);
            $socket = \socket_create(\str_contains($m[1], ':') ? \AF_INET6 : \AF_INET, \SOCK_STREAM | \SOCK_CLOEXEC, \SOL_TCP);
            // SO_REUSEADDR as stream_socket_server() sets it: connections in TIME_WAIT don't block a restart
            if (!@\socket_set_option($socket, \SOL_SOCKET, \SO_REUSEADDR, 1) || !@\socket_set_option($socket, \SOL_SOCKET, \SO_REUSEPORT, 1)
                || !@\socket_bind($socket, $m[1], (int) $m[2]) || !@\socket_listen($socket, 65535)) {
                $errno = \socket_last_error($socket);
                throw new \RuntimeException("Could not listen at $address: " . \socket_strerror($errno), $errno);
            }
            $listener = \socket_export_stream($socket);
            // Read by stream_socket_accept() from the listener's context
            \stream_context_set_option($listener, 'socket', 'tcp_nodelay', true);
        } else {
            $listener = @\stream_socket_server("tcp://$address", $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                \stream_context_create(['socket' => ['so_reuseport' => true, 'tcp_nodelay' => true, 'backlog' => 65535]]));
            if (false === $listener) {
                throw new \RuntimeException("Could not listen at $address: $errstr", $errno);
            }
        }
        \stream_set_blocking($listener, false);

        return $listener;
    }

    /**
     * A connected pair of Unix sockets, close-on-exec where possible, see listen(): a process
     * the application starts must not hold the worker's end of its pipe to the master.
     *
     * @return array{0: resource, 1: resource}
     */
    public static function socketPair(): array
    {
        if (\defined('SOCK_CLOEXEC')) {
            \socket_create_pair(\AF_UNIX, \SOCK_STREAM | \SOCK_CLOEXEC, 0, $pair);

            return [\socket_export_stream($pair[0]), \socket_export_stream($pair[1])];
        }

        return \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
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