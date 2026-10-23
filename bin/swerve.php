<?php

/*
 * Find the path to `vendor/autoload.php`.
 */

use phasync\Util\Console;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\Cache;
use Swerve\CLI\Address;
use Swerve\CLI\Args;
use Swerve\ClientRequest;
use Swerve\Http\HttpServer;
use Swerve\Http\TrustedProxies;
use Swerve\ClassicMode;
use Swerve\Mode;
use Swerve\PersistentMode;
use Swerve\Swerve;
use Swerve\Util\Classic\BuiltinRunner;
use Swerve\Util\Classic\ServerProcess;
use Swerve\Util\Classic\ServerRunner;
use Swerve\Util\Classic\ServiceServer;
use Swerve\Util\Cluster;
use Swerve\Util\Dispatcher;
use Swerve\Util\Logger;
use Swerve\Util\Plug;
use Swerve\Util\System;
use Swerve\Util\Topics;
use Swerve\Util\Worker;

// Composer's vendor/bin proxy says where the autoloader is. When swerve is installed in that
// vendor directory (an application's), the worker loads only swerve's own dependency closure and
// the adapters', never the application's classes or its Composer "files": a request's own
// require of vendor/autoload.php then runs them fresh, as under php-fpm (see WorkerAutoloader).
// Otherwise: that autoloader, or swerve's own vendor directory in a checkout, or the project's
// when installed at vendor/phasync/swerve.
require_once __DIR__ . '/../src/Util/WorkerAutoloader.php';
require_once __DIR__ . '/../src/Util/Plug.php';
$vendorDir = isset($GLOBALS['_composer_autoload_path'])
    ? \dirname($GLOBALS['_composer_autoload_path'])
    : (\is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor' : \dirname(__DIR__, 3));
$rootInstallPath = \is_file("$vendorDir/composer/installed.php")
    ? ((require "$vendorDir/composer/installed.php")['root']['install_path'] ?? \getcwd())
    : \getcwd();
// Without the extension, application code (the adapters, the frameworks they run, every other
// vendor package; never phasync, swerve, phasync/net or the plug's own babel and hooked) is
// translated by the plug to call phasync/hooked's cooperative replacements instead of blocking.
// Installed here, before the fork: see Plug.
Plug::installIfNeeded($vendorDir, $rootInstallPath);
if (isset($GLOBALS['_composer_autoload_path']) && \Swerve\Util\WorkerAutoloader::applies($vendorDir)) {
    \Swerve\Util\WorkerAutoloader::register($vendorDir, $rootInstallPath);
} else {
    $autoload = $GLOBALS['_composer_autoload_path']
        ?? (\is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../autoload.php');
    require Plug::path($autoload, \dirname($autoload));
}
unset($vendorDir, $rootInstallPath);

// swerve is a long-running process, like Node.js: php.ini's limits for shared hosting don't apply,
// and memory is the container's to bound. These settings override php.ini (which still loads the
// extensions); most only take effect at startup, so one restart applies them, and the extension's
// own restart below keeps them. An explicit -d on the command line wins, coming after these. Code
// changes reach the workers only through a reload (--watch, SIGHUP), which invalidates opcache's
// scripts, so nothing checks timestamps.
// The command line as the operator gave it, for a reload to start over from
if (false === \getenv('SWERVE_ARGS')) {
    \putenv('SWERVE_ARGS=' . \json_encode(phasync\ext\_original_args()));
}
if ('1' !== \getenv('SWERVE_INI_REEXEC')) {
    $ini = [
        'memory_limit'                         => '-1',
        'max_execution_time'                   => '0',
        'max_input_time'                       => '-1',
        'open_basedir'                         => '',
        'disable_functions'                    => '',
        'disable_classes'                      => '',
        'auto_prepend_file'                    => '',
        'auto_append_file'                     => '',
        'realpath_cache_size'                  => '64M',
        'realpath_cache_ttl'                   => '86400',
        'opcache.enable_cli'                   => '1',
        // The tracing JIT without register allocation (CRTO 1054, not 'tracing' = 1254). With
        // register allocation, PHP 8.5 (8.5.11 and the PHP-8.5 branch as of 2026-10) can keep a
        // variable only in a register across traces; when an internal call such as
        // Fiber::resume() throws into a catch in the same function, the VM finds the variable
        // undefined. Seen in phasync's event loop as a worker stops. 1054 keeps tracing; go back
        // to 'tracing' once PHP fixes it.
        'opcache.jit'                          => '1054',
        'opcache.jit_buffer_size'              => '64M',
        'opcache.memory_consumption'           => '4096',
        'opcache.interned_strings_buffer'      => '64',
        'opcache.max_accelerated_files'        => '100000',
        'opcache.max_file_size'                => '0',
        'opcache.validate_timestamps'          => '0',
        'opcache.file_update_protection'       => '0',
        'opcache.enable_file_override'         => '1',
        'opcache.save_comments'                => '1',
        'opcache.file_cache'                   => '',
        'opcache.file_cache_consistency_checks' => '0',
        'opcache.consistency_checks'           => '0',
        'opcache.protect_memory'               => '0',
        'opcache.preload'                      => '',
        ...Swerve::registeredIni(), // what installed packages need, see Swerve::ini()
    ];
    $flags = \extension_loaded('Zend OPcache') ? [] : ['-d', 'zend_extension=opcache'];
    foreach ($ini as $name => $value) {
        \array_push($flags, '-d', "$name=$value");
    }
    \putenv('SWERVE_INI_REEXEC=1');
    \pcntl_exec(\PHP_BINARY, [...$flags, ...phasync\ext\_original_args()]);
}

// Load ext-phasync (it ships inside phasync) with --ext: swerve stops if it cannot. May restart this process once.
if (\in_array('--ext', $argv, true)) {
    try {
        phasync\ext\ensure_loaded();
    } catch (\RuntimeException $e) {
        \fwrite(\STDERR, 'swerve: --ext: '.$e->getMessage()."\n");
        exit(1);
    }
}

pcntl_async_signals(true);

/*
 * Log lines to a pipe or socket nobody reads (a stalled log consumer) are dropped rather than
 * block: a blocked master would never act on SIGTERM, and a blocked worker serves nothing. Not
 * for a terminal, whose file description the shell shares.
 */
foreach ([\STDOUT, \STDERR] as $out) {
    if (!\stream_isatty($out)) {
        \stream_set_blocking($out, false);
    }
}

/**
 * Ensure we don't pollute the global namespace.
 */
(function () use ($argv) {
    /**
     * Colorful terminal.
     */
    $term = new Console(\STDOUT);

    /**
     * Argument parsing.
     *
     * @var Args
     */
    $args = require __DIR__.'/../inc/args.php';

    /*
     * Validate arguments or display --help / --version
     */
    if ($error = $args->isInvalid()) {
        \fwrite(\STDERR, "swerve: $error\nRun `swerve --help` for the options.\n");
        exit(2);
    }
    if ($args->help) {
        $term->write('<!yellow>Usage:<!> <!underline>swerve<!> '.$args->getShortArgumentList()."\n\n");
        $term->write($args->getArgumentList()."\n");
        exit(0);
    }
    if ($args->version) {
        echo 'swerve ', Swerve::getVersion(), ' (PHP ', \PHP_VERSION, ', phasync ', \Composer\InstalledVersions::getPrettyVersion('phasync/phasync'), ', ext-phasync ', \phpversion('phasync') ?: 'not loaded', ")\n";
        exit(0);
    }
    if (!$args->quiet && \stream_isatty(\STDOUT)) {
        $term->write('<!bold>swerve '.Swerve::getVersion()."<!> <!yellow>(beta: expect changes until 1.0)<!>\n");
    }

    /*
     * What to serve: ./swerve.php, which returns the mode, evaluated in each worker after the fork
     * (the master loads no application code); else the command line, as php -S's, for Classic.
     */
    $cwd        = \getcwd();
    $swerveFile = \is_file("$cwd/swerve.php") ? "$cwd/swerve.php" : null;
    $root       = null;
    $router     = null;
    if (null !== $swerveFile) {
        if ('' !== $args->path || '' !== $args->docroot) {
            \fwrite(\STDERR, "swerve: $swerveFile exists; configure the public root and the router there\n");
            exit(2);
        }
        $appDir = $cwd;
    } else {
        // A directory is the public root, a file the router: `swerve`, `swerve public`, `swerve router.php`, `swerve public router.php`
        $positional = \array_values(\array_filter([$args->path, $args->router], static fn ($a) => '' !== $a));
        if ('' !== $args->docroot) {
            $root = $args->docroot;
        }
        foreach ($positional as $i => $arg) {
            if (\is_dir($arg) && 0 === $i && null === $root) {
                $root = $arg;
            } elseif (\is_file($arg) && null === $router) {
                $router = $arg;
            } else {
                \fwrite(\STDERR, "swerve: $arg is not " . (\is_dir($arg) ? 'expected here: give one public root, first' : (\file_exists($arg) ? 'a directory or a file' : 'a directory or a file that exists')) . "\n");
                exit(2);
            }
        }
        $root   = \realpath($root ?? '.');
        $router = null === $router ? "$root/index.php" : \realpath($router);
        if (!\is_file($router)) {
            // Nothing to serve here: perhaps the application is configured further up
            for ($dir = \dirname($cwd); !\is_file("$dir/swerve.php") && \dirname($dir) !== $dir; $dir = \dirname($dir)) {
            }
            \fwrite(\STDERR, "swerve: $router not found: give the public root and the router (swerve [dir] [router.php]), or write a swerve.php"
                . (\is_file("$dir/swerve.php") ? " (there is one in $dir)" : '') . "\n");
            exit(2);
        }
        $appDir = $root;
    }

    $http      = \array_map(Address::normalize(...), $args->http);
    $addresses = $http;
    if (\count(\array_unique($addresses)) < \count($addresses)) {
        $twice = \implode(' ', \array_unique(\array_diff_assoc($addresses, \array_unique($addresses))));
        \fwrite(\STDERR, "swerve: $twice more than once\n");
        exit(2);
    }
    /*
     * Argument verbosity. Without -v: notices and up, so that reloads, recycles, drained
     * workers and shutdown are logged.
     */
    if ($args->verbosity >= 2) {
        $logLevel = LogLevel::DEBUG;
    } elseif ($args->verbosity == 1) {
        $logLevel = LogLevel::INFO;
    } else {
        $logLevel = LogLevel::NOTICE;
    }

    $workerCount = 'auto' === $args->workers ? System::getCPUCount() : (int) $args->workers;
    // The column after the time: a worker's slot number, right-aligned; blank for the master
    $source = \str_repeat(' ', \strlen((string) ($workerCount - 1)));
    $access = !$args->noAccessLog;

    /*
     * Logger Interface. With --log, PHP's own errors go to the file too: after a fatal error
     * such as memory_limit, PHP still writes its message, where a handler of ours may have no
     * memory left to run.
     */
    if ($args->log) {
        $file = @\fopen($args->log, 'a');
        if (false === $file) {
            \fwrite(\STDERR, "swerve: Can't open {$args->log} for writing\n");
            exit(1);
        }
        \ini_set('log_errors', '1');
        \ini_set('error_log', $args->log);
        \ini_set('display_errors', '0');
        $logger = new Logger($file, $source, $logLevel, $args->log, $access);
    } elseif ($args->quiet) {
        \ini_set('display_errors', '0');
        \ini_set('log_errors', '0');
        $logger = new NullLogger();
    } else {
        $logger = new Logger(\STDOUT, $source, $logLevel, access: $access);
    }

    // Each worker says so at info level; asked for, and then off, is worth a warning, once
    if (!$args->isDefault('maxMemory') && \str_ends_with($args->maxMemory, '%') && (int) $args->maxMemory > 0 && \ini_parse_quantity((string) \ini_get('memory_limit')) <= 0) {
        $logger->warning('Memory recycling is off: memory_limit is -1, so --max-memory={max} is no limit; give a size such as --max-memory=512M', ['max' => $args->maxMemory]);
    }

    /*
     * Every worker listens with SO_REUSEPORT, which would let a second swerve on the address
     * (a forgotten one, or one started by hand next to systemd's) silently take a share of the
     * connections. Bound once without it, before any worker listens, the address is refused
     * when anything listens there.
     */
    foreach ($addresses as $address) {
        if (\str_starts_with($address, 'unix:')) {
            // One listener for every worker, made here and inherited: see System::listen()
            try {
                System::listen($address);
            } catch (\RuntimeException $e) {
                $logger->critical('The server failed to start: {error}', ['error' => $e->getMessage()]);
                exit(1);
            }
            continue;
        }
        $probe = @\stream_socket_server("tcp://$address", $errno, $errstr, \STREAM_SERVER_BIND);
        if (false === $probe) {
            $logger->critical('The server failed to start: {address} is already in use ({error})', ['address' => $address, 'error' => $errstr]);
            exit(1);
        }
        \fclose($probe);
    }

    /*
     * Classic: the processes running scripts, shared by the workers. With the extension, its
     * request server, which the master starts once the first worker reports a Classic
     * application; without, a php -S pool per worker.
     */
    $classicWorkers = 'auto' === $args->classicWorkers ? $workerCount : (int) $args->classicWorkers;
    $classic        = \extension_loaded('phasync') ? new ServerProcess($logger, $classicWorkers) : null;

    /*
     * The master process forks the workers, and supervises them until stopped.
     */
    $cluster = new Cluster(
        $workerCount,
        $logger,
        (float) $args->grace,
        (float) $args->linger,
        (float) $args->watchdog,
        $args->watch ? $appDir : null,
        \sprintf('swerve %s serving %s on %s with %d worker%s%s', Swerve::getVersion(), $swerveFile ?? "$root through $router",
            \implode(', ', \array_map(static fn ($a) => 'http' . (\str_starts_with($a, 'unix:') ? '+unix://' . \substr($a, 5) : "://$a"), $addresses)),
            $workerCount, 1 === $workerCount ? '' : 's', $args->watch ? ', reloading when PHP files change' : ''),
        (int) \ini_parse_quantity($args->cacheSize),
        static function () use ($logger): void {
            // A reload: start over as a fresh process, from the operator's command line
            System::closeListeners();
            System::cleanup();
            \putenv('SWERVE_INI_REEXEC');
            \putenv('PHASYNC_EXT_REEXEC');
            \pcntl_exec(\PHP_BINARY, \json_decode((string) \getenv('SWERVE_ARGS'), true));
            $logger->critical('Reload failed: swerve could not restart itself ({error})', ['error' => \pcntl_strerror(\pcntl_get_last_error())]);
            exit(1);
        },
        $classic,
    );
    $worker = $cluster->run();
    if (\is_int($worker)) {
        foreach ($addresses as $address) {
            if (\str_starts_with($address, 'unix:')) {
                System::unlinkSocket($address);
            }
        }
        exit($worker);
    }

    /*
     * From here on: a worker process. Load the application.
     */
    $logger = $worker->logger;
    Swerve::setLog($logger);
    if (Plug::isInstalled()) {
        $logger->info('ext-phasync is not loaded: application code runs through the plug (phasync/babel and phasync/hooked), cache in {dir}', ['dir' => Plug::cacheDir()]);
    }
    Worker::refreshAutoloader();
    $proxies = $args->trustedProxy ? new TrustedProxies($args->trustedProxy) : null;
    // Loaded in the worker's event loop: the application may start coroutines as it loads,
    // such as a subscriber that runs for the worker's whole life
    phasync::run(static function () use ($swerveFile, $root, $router, $args, $logger, $worker, $http, $proxies, $workerCount, $classicWorkers) {
        // A coroutine of the worker or a service (each request's frame has its own handler, see
        // HttpConnection) that fails with nobody awaiting it is logged, and the worker serves on:
        // without a handler the failure would end this run() once every other coroutine had
        phasync::frame()->setExceptionHandler(static fn (\Throwable $e) => $logger->error('Unhandled exception in a coroutine: {exception}', ['exception' => $e]));
        try {
            Swerve::startWorker();
        } catch (\Throwable $e) {
            $logger->critical('A Swerve::onWorkerStart() callback failed: {exception}', ['exception' => $e]);
            exit(Worker::EXIT_BAD_APP);
        }
        try {
            Cache::$loader = phasync::getFiber();
            if (null !== $swerveFile) {
                // Its paths are relative to its own directory
                \chdir(\dirname($swerveFile));
                $mode = require Plug::path($swerveFile, \dirname($swerveFile));
                if (!$mode instanceof Mode) {
                    $logger->critical('{file} returned {value}; it must return the mode: ClassicMode::serve(), PersistentMode::serve() or AsyncMode::serve()', ['file' => $swerveFile, 'value' => \get_debug_type($mode)]);
                    exit(Worker::EXIT_BAD_APP);
                }
            } else {
                $mode = new ClassicMode($root, $router);
            }
            Cache::$loader = null;
            $runner   = null;
            $services = null;
            if ($mode instanceof ClassicMode) {
                // Swerve's cache and pub/sub for the scripts, which run outside the workers
                $services = new ServiceServer(\sys_get_temp_dir() . '/swerve-' . \posix_getppid() . '-services-' . \posix_getpid() . '.sock');
                $services->listen();
                if (\extension_loaded('phasync')) {
                    (Topics::$toMaster)(ServerProcess::TOPIC, \serialize([$mode->publicRoot, $mode->router]));
                    $runner = new ServerRunner(ServerProcess::$socket, $services->path);
                    $runner->awaitServer();
                } else {
                    // php -S: a pool per worker, each its share
                    $runner = new BuiltinRunner($mode->publicRoot, \max(1, \intdiv($classicWorkers, $workerCount)), services: $services->path);
                }
            }
            $dispatcher = new Dispatcher($mode, $swerveFile ?? $mode->router, $runner);
        } catch (\Throwable $e) {
            $logger->critical('Loading {what} failed: {exception}', ['what' => $swerveFile ?? 'the application', 'exception' => $e]);
            exit(Worker::EXIT_BAD_APP);
        }
        if (0 === $worker->slot) {
            $logger->notice('{mode}', ['mode' => match (true) {
                $mode instanceof ClassicMode => 'Classic (' . (null !== $runner && $runner instanceof ServerRunner ? 'the extension\'s request server' : 'php -S') . ", up to $classicWorkers processes)",
                $mode instanceof PersistentMode => "Persistent, $workerCount worker" . (1 === $workerCount ? "" : "s"),
                default => "Async, $workerCount worker" . (1 === $workerCount ? "" : "s"),
            } . ([] === $mode->asyncRoutes() ? '' : ', async routes ' . \implode(' ', \array_map(static fn ($r) => $r[0], $mode->asyncRoutes())))]);
        }
        $worker->setLimits($args->maxMemory, (int) $args->maxRequests);

        $handler = static function (ClientRequest $request) use ($dispatcher, $worker) {
            $id = $worker->requestStarted(static fn () => $request->getMethod() . ' ' . $request->getTarget());
            try {
                $dispatcher($request);
            } finally {
                $worker->requestDone($id);
            }
        };

        // Every worker serves HTTP/1.1 itself on the same address (SO_REUSEPORT), and the
        // kernel spreads new connections over them
        $maxBody = (int) $args->maxBody ?: \PHP_INT_MAX;
        $servers = [];
        foreach ($http as $address) {
            $server = new HttpServer($address, $handler, $logger, $maxBody, $proxies);
            try {
                $server->listen();
            } catch (\Throwable $e) {
                // Before 'R': the master counts it as a failed start
                $logger->critical('{exception}', ['exception' => $e]);
                exit(1);
            }
            $servers[] = $server;
        }
        foreach ($servers as $server) {
            $worker->serve($server->run(...), $server->drain(...));
        }
        if (null !== $services) {
            $worker->serve($services->run(...), $services->drain(...));
        }
        $worker->ready();
    });
    exit(0);
})();
