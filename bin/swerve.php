<?php

/*
 * swerve's master: listens, accepts every connection, and hands each request to a worker
 * (Swerve\Lot\Lot). The workers are forked from it, as php-fpm's are, so they share its
 * opcache and APCu memory; each runs inc/worker.php, and through it the application's
 * swerve.php, again and again (run_next()). A worker that exits is replaced. The WebSockets
 * of short-lived lifecycles (WordPress's) move to WebSocket workers, started as they are needed.
 *
 *     swerve [--http=127.0.0.1:8080|unix:/path] [--workers=N] [--log=FILE] [--grace=SECONDS] [--watch] [swerve.php | -S [dir] [router.php]]
 */

use phasync\ext;
use Psr\Log\LogLevel;
use Swerve\Lot\Lot;
use Swerve\Lot\Supervisor;
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
        // bin/phasync, which loads the extension phasync bundles and turns its keywords on; once
        $phasync = \dirname((new \ReflectionClass(\phasync::class))->getFileName()) . '/bin/phasync';
        if (\getenv('SWERVE_STARTED_OVER')) {
            // An extension loaded with -d that phasync does not support: phasync's run() says why
            try {
                \phasync::run(static fn () => null);
                $message = 'the phasync extension ' . \phpversion('phasync') . ' is loaded, without phasync.keywords';
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            \fwrite(\STDERR, "swerve: $message\n");
            exit(1);
        }
        \putenv('SWERVE_STARTED_OVER=1');
        \pcntl_exec(\PHP_BINARY, [...$flags, $phasync, ...$argv]);
        \fwrite(\STDERR, "swerve: could not start over through $phasync\n");
        exit(1);
    }
    \putenv('SWERVE_STARTED_OVER');
    $http    = '127.0.0.1:8080';
    $workers = System::getCPUCount();
    $log     = null;
    $grace   = 10.0;
    $watch   = false;
    $serve   = false;
    $paths   = [];
    foreach (\array_slice($argv, 1) as $arg) {
        match (true) {
            \str_starts_with($arg, '--http=')    => $http    = \substr($arg, 7),
            \str_starts_with($arg, '--workers=') => $workers = (int) \substr($arg, 10),
            \str_starts_with($arg, '--log=')     => $log     = \substr($arg, 6),
            \str_starts_with($arg, '--grace=')   => $grace   = (float) \substr($arg, 8),
            '--watch' === $arg                   => $watch   = true,
            '-S' === $arg                        => $serve   = true,
            \str_starts_with($arg, '-')          => $unknown = $arg,
            default                              => $paths[] = $arg,
        };
    }
    $fail = static function (string $message): never {
        \fwrite(\STDERR, "swerve: $message\nusage: swerve [--http=127.0.0.1:8080|unix:/path] [--workers=N] [--log=FILE] [--grace=SECONDS] [--watch] [swerve.php | -S [dir] [router.php]]\n");
        exit(1);
    };
    if (isset($unknown)) {
        $fail("unknown option $unknown");
    }
    $env = [];
    if ($serve) {
        // php -S's way: the files of a directory, its PHP files run, and the rest to the router
        // (by default the directory's index.php; without one, a 404)
        if (\count($paths) > 2) {
            $fail('-S takes a directory and a router, and no swerve.php');
        }
        $dir = \realpath($paths[0] ?? '.');
        if (false === $dir || !\is_dir($dir)) {
            $fail(($paths[0] ?? '.') . ' is not a directory');
        }
        $router = isset($paths[1]) ? \realpath($paths[1]) : (\is_file("$dir/index.php") ? "$dir/index.php" : null);
        if (false === $router || null !== $router && !\is_file($router)) {
            $fail("{$paths[1]} not found");
        }
        $appFile = \realpath(__DIR__ . '/../inc/serve.php');
        $workDir = $dir;
        $env     = ['SWERVE_DIR' => $dir, 'SWERVE_ROUTER' => $router ?? ''];
    } else {
        if (\count($paths) > 1) {
            $fail('one swerve.php at most');
        }
        $app     = $paths[0] ?? './swerve.php';
        $appFile = \realpath($app);
        if (false === $appFile) {
            $fail("$app not found");
        }
        $workDir = \dirname($appFile);
    }
    // The workers are forked from this process, so the settings PHP reads only as it starts are
    // this process's: it starts itself over with them, once. A long-running server, like Node.js:
    // php.ini's limits for shared hosting don't apply. Each worker may use a quarter of the memory,
    // at most 4 GiB, as V8 sizes Node's heap; a worker that reaches it dies and another starts.
    // Code changes reach the workers by a restart, so nothing checks timestamps. An explicit -d
    // on the command line wins.
    if (false === \getenv('SWERVE_MASTER')) {
        $ini = [
            'memory_limit'                          => \intdiv(\min(System::getMemorySize() >> 2, 4 << 30), 1 << 20) . 'M',
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
        $env += ['SWERVE_MASTER' => '1', 'SWERVE_AUTOLOAD' => \realpath($autoload), 'SWERVE_APP' => $appFile];
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
        \chmod($path, 0o666); // a front server running as another user connects to it, as to php-fpm's
    }
    if (false === $listener) {
        $logger->critical('Cannot listen on {address}: {error}', ['address' => $http, 'error' => $error ?: \error_get_last()['message'] ?? '']);
        exit(1);
    }

    // The WebSockets of short-lived lifecycles move to the WebSocket workers over this pair: the
    // workers write to one end (their fd 4), the WebSocket workers read the other (their fd 3)
    [$toWebSockets, $webSockets] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_SEQPACKET, 0);
    $supervisor                  = new Supervisor(new Lot($listener, $logger), $logger, $workers, \dirname(__DIR__) . '/inc', $out, $workDir, $toWebSockets, $webSockets, $grace, $watch ? $workDir : null);
    try {
        phasync::run($supervisor->run(...));
    } finally {
        if (null !== $socket) {
            @\unlink($socket);
        }
    }
})($autoload, $argv);
