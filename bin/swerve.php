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
use Swerve\RequestHandler;
use Swerve\StaticFiles;
use Swerve\Swerve;
use Swerve\Util\Adapters;
use Swerve\Util\Cluster;
use Swerve\Util\Logger;
use Swerve\Util\LoggingContext;
use Swerve\Util\System;
use Swerve\Util\Worker;

// Composer's vendor/bin proxy says where the autoloader is. Otherwise: swerve's own vendor
// directory in a checkout, or the project's when installed at vendor/phasync/swerve.
require $GLOBALS['_composer_autoload_path']
    ?? (\is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../autoload.php');

// swerve is a long-running process, like Node.js: php.ini's limits for shared hosting don't apply,
// and memory is the container's to bound. These settings override php.ini (which still loads the
// extensions); most only take effect at startup, so one restart applies them, and the extension's
// own restart below keeps them. An explicit -d on the command line wins, coming after these. Code
// changes reach the workers only through a reload (--watch, SIGHUP), which invalidates opcache's
// scripts, so nothing checks timestamps.
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
        'opcache.jit'                          => 'tracing',
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

// Load phasync-ext (it ships inside phasync): --ext must succeed or swerve stops; composer.json's setting only
// logs a notice when the extension cannot load. Either may restart this process once.
$extInComposer = phasync\ext_enabled();
if (\in_array('--ext', $argv, true)) {
    try {
        phasync\ext\ensure_loaded();
    } catch (\RuntimeException $e) {
        \fwrite(\STDERR, 'swerve: --ext: '.$e->getMessage()."\n");
        exit(1);
    }
} elseif ($extInComposer) {
    phasync\try_enable_ext();
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
(function () use ($argv, $extInComposer) {
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
        echo 'swerve ', Swerve::getVersion(), ' (PHP ', \PHP_VERSION, ', phasync ', \Composer\InstalledVersions::getPrettyVersion('phasync/phasync'), ', phasync-ext ', \phpversion('phasync') ?: 'not loaded', ")\n";
        exit(0);
    }
    if (!$args->quiet && \stream_isatty(\STDOUT)) {
        $term->write('<!bold>swerve '.Swerve::getVersion()."<!> <!yellow>(beta: expect changes until 1.0)<!>\n");
    }

    /**
     * The application directory: the directory of the swerve.php given, else the current one.
     * Then the adapter that provides the entry point (see Adapters), found without loading any
     * of its code.
     */
    $explicitFile = !$args->isDefault('swervefile');
    $swerveFile   = \str_starts_with($args->swervefile, '/') ? $args->swervefile : \getcwd() . '/' . $args->swervefile;
    $appDir       = $explicitFile ? \dirname($swerveFile) : \getcwd();
    try {
        $installed = Adapters::installed($appDir);
        $adapter   = Adapters::select($installed, $args->adapter ?: null, Adapters::configured($appDir));
    } catch (\RuntimeException $e) {
        \fwrite(\STDERR, 'swerve: ' . $e->getMessage() . "\n");
        exit(2);
    }
    $entry = $installed[$adapter] ?? null;
    if (null !== $entry && $explicitFile) {
        \fwrite(\STDERR, "swerve: {$args->swervefile} is given, but the adapter $adapter provides the entry point: use --adapter=swerve to load it\n");
        exit(2);
    }

    /**
     * Check that `swerve.php` file exists. The application is loaded in each worker, after
     * the fork, so that a reload runs the current code. Symlinks are not resolved: a deploy
     * that points `current` at a new release, then reloads, runs the new release.
     */
    if (null === $entry && !\is_file($swerveFile)) {
        \fwrite(\STDERR, "swerve: {$args->swervefile} not found\n");
        exit(1);
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

    if (null !== $entry && \is_file("$appDir/swerve.php")) {
        $logger->notice('{file} is ignored: the adapter {adapter} provides the entry point', ['file' => "$appDir/swerve.php", 'adapter' => $adapter]);
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
     * The master process forks the workers, and supervises them until stopped.
     */
    $cluster = new Cluster(
        $workerCount,
        $logger,
        (float) $args->grace,
        (float) $args->linger,
        (float) $args->watchdog,
        $args->watch ? $appDir : null,
        \sprintf('swerve %s serving %s on %s with %d worker%s%s', Swerve::getVersion(), null === $entry ? $args->swervefile : "adapter $adapter",
            \implode(', ', \array_map(static fn ($a) => 'http' . (\str_starts_with($a, 'unix:') ? '+unix://' . \substr($a, 5) : "://$a"), $addresses)),
            $workerCount, 1 === $workerCount ? '' : 's', $args->watch ? ', reloading when PHP files change' : ''),
        (int) \ini_parse_quantity($args->cacheSize),
        $extInComposer,
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
    Worker::refreshAutoloader();
    // Loaded in the worker's event loop: the application may start coroutines as it loads,
    // such as a subscriber that runs for the worker's whole life
    // Before the application loads, which may change the working directory
    $files = '' !== $args->public ? new StaticFiles($args->public) : null;
    $proxies = $args->trustedProxy ? new TrustedProxies($args->trustedProxy) : null;
    phasync::run(static function () use ($swerveFile, $appDir, $adapter, $entry, $args, $logger, $worker, $http, $files, $proxies) {
        try {
            Swerve::startWorker();
        } catch (\Throwable $e) {
            $logger->critical('A Swerve::onWorkerStart() callback failed: {exception}', ['exception' => $e]);
            exit(Worker::EXIT_BAD_APP);
        }
        try {
            Cache::$loader = phasync::getFiber();
            $app           = null === $entry ? require $swerveFile : $entry($appDir);
            Cache::$loader = null;
        } catch (\Throwable $e) {
            $logger->critical('Loading {what} failed: {exception}', ['what' => null === $entry ? $swerveFile : "the adapter $adapter", 'exception' => $e]);
            exit(Worker::EXIT_BAD_APP);
        }
        if (!$app instanceof RequestHandler) {
            $logger->critical('{what} returned {value}; it must return a Swerve\\RequestHandler', ['what' => null === $entry ? $args->swervefile : "The entry $entry of the adapter $adapter", 'value' => \get_debug_type($app)]);
            exit(Worker::EXIT_BAD_APP);
        }
        $worker->setLimits($args->maxMemory, (int) $args->maxRequests);

        $app = $app->handler;
        if (null !== $files) {
            $app = $files->wrap($app); // files first; the application gets what is not one
        }
        $handler = static function (ClientRequest $request) use ($app, $worker) {
            $id = $worker->requestStarted(static fn () => $request->getMethod() . ' ' . $request->getTarget());
            try {
                $app($request);
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
        $worker->ready();
    }, [], new LoggingContext($logger));
    exit(0);
})();
