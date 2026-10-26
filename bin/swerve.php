<?php

/*
 * swerve's master: listens, accepts every connection, and hands each request to a worker
 * (Swerve\Lot\Lot). The workers are forked from it, as php-fpm's are, so they share its
 * opcache and APCu memory; each runs inc/worker.php, and through it the application's
 * swerve.php, again and again (run_next()). A worker that exits is replaced. The WebSockets
 * of short-lived lifecycles (WordPress's) move to one more process, the WebSocket worker.
 *
 *     swerve [--http=127.0.0.1:8080] [--workers=N] [--log=FILE] [swerve.php]
 */

use phasync\ext;
use Psr\Log\LogLevel;
use Swerve\Lot\Lot;
use Swerve\Util\Logger;
use Swerve\Util\System;

$autoload = $GLOBALS['_composer_autoload_path']
    ?? (\is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../../autoload.php');
require $autoload;

(static function (string $autoload, array $argv): void {
    if (!\function_exists('phasync\ext\run_next')) {
        \fwrite(\STDERR, "swerve: needs ext-phasync with run_next()\n");
        exit(1);
    }
    $http    = '127.0.0.1:8080';
    $workers = System::getCPUCount();
    $log     = null;
    $app     = './swerve.php';
    foreach (\array_slice($argv, 1) as $arg) {
        match (true) {
            \str_starts_with($arg, '--http=')    => $http    = \substr($arg, 7),
            \str_starts_with($arg, '--workers=') => $workers = (int) \substr($arg, 10),
            \str_starts_with($arg, '--log=')     => $log     = \substr($arg, 6),
            \str_starts_with($arg, '-')          => throw new \InvalidArgumentException("swerve: unknown option $arg"),
            default                              => $app     = $arg,
        };
    }
    $appFile = \realpath($app);
    if (false === $appFile) {
        \fwrite(\STDERR, "swerve: $app not found\n");
        exit(1);
    }
    // The workers are forked from this process, so the settings PHP reads only as it starts are
    // this process's: it starts itself over with them, once. A long-running server, not shared
    // hosting; opcache and APCu, when loaded, are shared by the workers.
    if (false === \getenv('SWERVE_MASTER')) {
        $php = [];
        $cmd = \explode("\0", \rtrim((string) \file_get_contents('/proc/self/cmdline'), "\0"));
        for ($i = 1; $i < \count($cmd) && \str_starts_with($cmd[$i], '-d'); ++$i) {
            $php[] = $cmd[$i];
            if ('-d' === $cmd[$i]) {
                $php[] = $cmd[++$i];
            }
        }
        foreach ([
            'memory_limit' => '-1', 'max_execution_time' => '0', 'opcache.enable_cli' => '1',
            'opcache.jit' => '1054', 'opcache.jit_buffer_size' => '64M', 'opcache.validate_timestamps' => '0',
            'apc.enable_cli' => '1',
        ] as $name => $value) {
            \array_push($php, '-d', "$name=$value");
        }
        // The environment, not putenv(): PHP undoes putenv() when a request ends, and the
        // workers' lifecycles are requests
        $env = ['SWERVE_MASTER' => '1', 'SWERVE_AUTOLOAD' => \realpath($autoload), 'SWERVE_APP' => $appFile];
        // Descriptors 3 and 4 are the children's channels (see below): taken here, so the PHP
        // that starts opens its own files (the script, opcache's lock) above them
        $null = \fopen('/dev/null', 'r');
        ext\dup2($null, 3);
        ext\dup2($null, 4);
        \pcntl_exec(\PHP_BINARY, [...$php, ...$argv], $env + \getenv());
        \fwrite(\STDERR, "swerve: could not start over with its settings\n");
        exit(1);
    }
    $out    = null === $log ? \STDERR : \fopen($log, 'a');
    $logger = new Logger($out, 'master', LogLevel::NOTICE);

    $listener = @\stream_socket_server("tcp://$http", $errno, $error);
    if (false === $listener) {
        $logger->critical('Cannot listen on {address}: {error}', ['address' => $http, 'error' => $error]);
        exit(1);
    }

    $inc = \dirname(__DIR__) . '/inc';

    $pids = [];
    $stop = static function (int $status = 0) use (&$pids): never {
        foreach ($pids as $pid) {
            \posix_kill($pid, \SIGTERM);
        }
        exit($status);
    };
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGTERM, static fn () => $stop());
    \pcntl_signal(\SIGINT, static fn () => $stop());

    // The WebSockets of short-lived lifecycles move to the WebSocket worker over this pair: the
    // workers write to one end (their fd 4), the WebSocket worker reads the other (its fd 3)
    [$toWebSockets, $webSockets] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);

    // Fork a process that runs $script with $descriptors' descriptors (fd 3 on), then $supervise
    // here; once the process is gone, another
    $spawn = static function (string $name, string $script, \Closure $descriptors, \Closure $supervise) use ($logger, $out, $appFile, &$pids, $stop): void {
        try {
            while (true) {
                $started = \microtime(true);
                $fds     = $descriptors();
                $pid     = \pcntl_fork();
                if (-1 === $pid) {
                    throw new \RuntimeException('fork() failed: ' . \pcntl_strerror(\pcntl_get_last_error()));
                }
                if (0 === $pid) {
                    // Its descriptors and stdio as ones that outlive this request; then this
                    // script ends, and run_next() starts $script
                    foreach ($fds as $n => $fd) {
                        ext\dup2($fd, 3 + $n);
                    }
                    ext\dup2(\fopen('/dev/null', 'r'), 0);
                    ext\dup2($out, 1);
                    ext\dup2($out, 2);
                    \chdir(\dirname($appFile));
                    ext\run_next($script);
                    exit(0);
                }
                $pids[$name] = $pid;
                $supervise();
                while (0 === \pcntl_waitpid($pid, $status, \WNOHANG)) {
                    phasync::sleep(0.01);
                }
                unset($pids[$name]);
                $logger->warning('{name} exited ({status}); starting another', ['name' => $name, 'status' => \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : 'signal ' . \pcntl_wtermsig($status)]);
                if (\microtime(true) - $started < 1) {
                    phasync::sleep(1); // failing at once: not in a tight loop
                }
            }
        } catch (\Throwable $e) {
            // A process nobody supervises would be lost quietly: stop instead
            $logger->critical('Supervising {name} failed: {exception}', ['name' => $name, 'exception' => $e]);
            $stop(1);
        }
    };

    phasync::run(static function () use ($listener, $logger, $workers, $inc, $spawn, $toWebSockets, $webSockets): void {
        $lot = new Lot($listener, $logger);
        phasync::go($lot->run(...));
        phasync::go(static fn () => $spawn('The WebSocket worker', "$inc/websocket-worker.php", static fn () => [$webSockets], static function (): void {
        }));
        for ($n = 0; $n < $workers; ++$n) {
            phasync::go(static function () use ($n, $lot, $inc, $spawn, $toWebSockets): void {
                $master = $channel = null;
                $spawn("Worker $n", "$inc/worker.php", static function () use (&$master, &$channel, $toWebSockets): array {
                    [$master, $channel] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);

                    return [$channel, $toWebSockets];
                }, static function () use (&$master, &$channel, $lot): void {
                    \fclose($channel);
                    $lot->serve($master); // until the worker is gone
                    \fclose($master);
                });
            });
        }
    });
})($autoload, $argv);
