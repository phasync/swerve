<?php

/*
 * swerve's master: listens, accepts every connection, and hands each request to a worker
 * (Swerve\Lot\Lot). The workers are forked from it, as php-fpm's are, so they share its
 * opcache and APCu memory; each runs inc/worker.php, and through it the application's
 * swerve.php, again and again (run_next()). A worker that exits is replaced. The WebSockets
 * of short-lived lifecycles (WordPress's) move to WebSocket workers, started as they are needed.
 *
 *     swerve [--http=127.0.0.1:8080|unix:/path] [--workers=N] [--log=FILE] [swerve.php]
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
    // The -d flags PHP was started with (php -d ... vendor/bin/swerve): kept when it starts over
    $cmd   = \explode("\0", \rtrim((string) \file_get_contents('/proc/self/cmdline'), "\0"));
    $flags = [];
    for ($i = 1; $i < \count($cmd) && \str_starts_with($cmd[$i], '-d'); ++$i) {
        $flags[] = $cmd[$i];
        if ('-d' === $cmd[$i]) {
            $flags[] = $cmd[++$i];
        }
    }
    if (!\extension_loaded('phasync') || !\ini_get('phasync.keywords')) {
        // Started on plain PHP (vendor/bin/swerve's shebang): start over through phasync's
        // bin/phasync, which loads the extension phasync bundles and turns its keywords on
        $phasync = \dirname((new \ReflectionClass(\phasync::class))->getFileName()) . '/bin/phasync';
        \pcntl_exec(\PHP_BINARY, [...$flags, $phasync, ...$argv]);
        \fwrite(\STDERR, "swerve: could not start over through $phasync\n");
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
            \str_starts_with($arg, '-')          => $unknown = $arg,
            default                              => $app     = $arg,
        };
    }
    if (isset($unknown)) {
        \fwrite(\STDERR, "swerve: unknown option $unknown\nusage: swerve [--http=127.0.0.1:8080|unix:/path] [--workers=N] [--log=FILE] [swerve.php]\n");
        exit(1);
    }
    $appFile = \realpath($app);
    if (false === $appFile) {
        \fwrite(\STDERR, "swerve: $app not found\n");
        exit(1);
    }
    // The workers are forked from this process, so the settings PHP reads only as it starts are
    // this process's: it starts itself over with them, once. A long-running server, like Node.js:
    // php.ini's limits for shared hosting don't apply, and memory is the container's to bound.
    // Code changes reach the workers by a restart, so nothing checks timestamps. An explicit -d
    // on the command line wins.
    if (false === \getenv('SWERVE_MASTER')) {
        $ini = [
            'memory_limit'                          => '-1',
            'max_execution_time'                    => '0',
            'max_input_time'                        => '-1',
            'open_basedir'                          => '',
            'disable_functions'                     => '',
            'disable_classes'                       => '',
            'auto_prepend_file'                     => '',
            'auto_append_file'                      => '',
            'realpath_cache_size'                   => '64M',
            'realpath_cache_ttl'                    => '86400',
            'opcache.enable_cli'                    => '1',
            'opcache.jit'                           => '1054', // not tracing: ext-phasync#47
            'opcache.jit_buffer_size'               => '64M',
            'opcache.memory_consumption'            => '4096',
            'opcache.interned_strings_buffer'       => '64',
            'opcache.max_accelerated_files'         => '100000',
            'opcache.max_file_size'                 => '0',
            'opcache.validate_timestamps'           => '0',
            'opcache.file_update_protection'        => '0',
            'opcache.enable_file_override'          => '1',
            'opcache.save_comments'                 => '1',
            'opcache.file_cache'                    => '',
            'opcache.file_cache_consistency_checks' => '0',
            'opcache.consistency_checks'            => '0',
            'opcache.protect_memory'                => '0',
            'opcache.preload'                       => '',
            'apc.enable_cli'                        => '1',
            'apc.shm_size'                           => '128M',
        ];
        $php = \extension_loaded('Zend OPcache') ? [] : ['-d', 'zend_extension=opcache'];
        foreach ($ini as $name => $value) {
            \array_push($php, '-d', "$name=$value");
        }
        \array_push($php, ...$flags);
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

    $address = "tcp://$http";
    $socket  = null; // a unix: address's file, removed as swerve stops
    if (\str_starts_with($http, 'unix:') || \str_starts_with($http, '/')) {
        $path    = \preg_replace('#^unix:(?://)?#', '', $http);
        $address = "unix://$path";
        // A socket file nobody listens on is a stale one of ours; anything else is not ours to remove
        if ('socket' === @\filetype($path) && false === @\stream_socket_client($address)) {
            \unlink($path);
        }
    }
    // A burst of connections waits in the kernel's queue, not for SYN retries: as long a queue as
    // the kernel allows (somaxconn caps it)
    $listener = @\stream_socket_server($address, $errno, $error, context: \stream_context_create(['socket' => ['backlog' => 65535]]));
    if (false !== $listener && isset($path)) {
        $socket = $path;
    }
    if (false === $listener) {
        $logger->critical('Cannot listen on {address}: {error}', ['address' => $http, 'error' => $error ?: \error_get_last()['message'] ?? '']);
        exit(1);
    }

    $inc = \dirname(__DIR__) . '/inc';

    $pids = [];
    $stop = static function (int $status = 0) use (&$pids, $socket): never {
        foreach ($pids as $pid) {
            \posix_kill($pid, \SIGTERM);
        }
        if (null !== $socket) {
            @\unlink($socket);
        }
        exit($status);
    };
    \pcntl_async_signals(true);
    \pcntl_signal(\SIGTERM, static fn () => $stop());
    \pcntl_signal(\SIGINT, static fn () => $stop());

    // The WebSockets of short-lived lifecycles move to the WebSocket workers over this pair: the
    // workers write to one end (their fd 4), the WebSocket workers read the other (their fd 3)
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
        // WebSocket workers on demand, which read the pair's end in turn: the first when a WebSocket
        // first waits there, another whenever one waits there unread for 10 ms (those running are
        // busy), up to one per worker. The master only watches the end; it never reads it.
        phasync::go(static function () use ($workers, $inc, $spawn, $webSockets): void {
            for ($running = 0; $running < $workers;) {
                phasync::readable($webSockets);
                if ($running > 0) {
                    phasync::sleep(0.01);
                    $waiting = [$webSockets];
                    $none    = null;
                    if (0 === \stream_select($waiting, $none, $none, 0)) {
                        continue; // taken meanwhile
                    }
                }
                $n = ++$running;
                phasync::go(static fn () => $spawn("WebSocket worker $n", "$inc/websocket-worker.php", static fn () => [$webSockets], static function (): void {
                }));
                phasync::sleep(0.5); // its start: until it reads, what waits is no sign of busy ones
            }
        });
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
