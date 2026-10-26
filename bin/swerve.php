<?php

/*
 * swerve's master: listens, accepts every connection, and hands each request to a worker
 * (Swerve\Lot\Lot). The workers are `php inc/worker.php` processes, each running the
 * application's swerve.php again and again (run_next()); a worker that exits is replaced.
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
    $out    = null === $log ? \STDERR : \fopen($log, 'a');
    $logger = new Logger($out, 'master', LogLevel::NOTICE);

    $listener = @\stream_socket_server("tcp://$http", $errno, $error);
    if (false === $listener) {
        $logger->critical('Cannot listen on {address}: {error}', ['address' => $http, 'error' => $error]);
        exit(1);
    }

    // The workers run PHP as this process does (its -d settings, the extension), with swerve's
    // ini on top: a long-running process, not shared hosting
    $php = [\PHP_BINARY];
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
    ] as $name => $value) {
        \array_push($php, '-d', "$name=$value");
    }
    $php[] = \dirname(__DIR__) . '/inc/worker.php';
    $env   = ['SWERVE_AUTOLOAD' => \realpath($autoload), 'SWERVE_APP' => $appFile] + \getenv();

    $procs = [];
    $stop  = static function (int $status = 0) use (&$procs): never {
        foreach ($procs as $proc) {
            \proc_terminate($proc);
        }
        exit($status);
    };
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGTERM, static fn () => $stop());
    \pcntl_signal(\SIGINT, static fn () => $stop());

    phasync::run(static function () use ($listener, $logger, $workers, $php, $env, $out, $appFile, &$procs, $stop): void {
        $lot = new Lot($listener, $logger);
        phasync::go($lot->run(...));
        for ($n = 0; $n < $workers; ++$n) {
            phasync::go(static function () use ($n, $lot, $logger, $php, $env, $out, $appFile, &$procs, $stop): void {
                try {
                    while (true) {
                        [$master, $worker] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);
                        $started           = \microtime(true);
                        $procs[$n]         = \proc_open($php, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out, 3 => $worker], $pipes, \dirname($appFile), $env);
                        \fclose($worker);
                        $lot->serve($master); // until the worker is gone
                        \fclose($master);
                        $status = \proc_close($procs[$n]);
                        $logger->warning('Worker {n} exited ({status}); starting another', ['n' => $n, 'status' => $status]);
                        if (\microtime(true) - $started < 1) {
                            phasync::sleep(1); // failing at once: not in a tight loop
                        }
                    }
                } catch (\Throwable $e) {
                    // A worker nobody supervises would be lost quietly: stop instead
                    $logger->critical('Supervising worker {n} failed: {exception}', ['n' => $n, 'exception' => $e]);
                    $stop(1);
                }
            });
        }
    });
})($autoload, $argv);
