<?php

/*
 * Find the path to `vendor/autoload.php`.
 */

use phasync\Util\Console;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\CLI\Address;
use Swerve\CLI\Args;
use Swerve\Swerve;
use Swerve\Util\Cluster;
use Swerve\Util\Logger;
use Swerve\Util\Plug;
use Swerve\Util\System;

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
// extensions); most only take effect at startup, so one restart applies them, and the worker-
// parent's own `php` invocation (built further down, same flags) gets them too. An explicit -d on
// the command line wins, coming after these. Code changes reach the workers only through a reload
// (--watch, SIGHUP), which starts a worker-parent of the next generation, a fresh `php` process:
// opcache.validate_timestamps stays 0, since that fresh compile is what picks up the change.
/**
 * @return array<string, string>
 */
function swerve_ini(): array
{
    return [
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
}

/**
 * -d flags for swerve_ini(), plus zend_extension=opcache first when nothing loaded it yet.
 *
 * @return list<string>
 */
function swerve_ini_flags(): array
{
    $flags = \extension_loaded('Zend OPcache') ? [] : ['-d', 'zend_extension=opcache'];
    foreach (swerve_ini() as $name => $value) {
        \array_push($flags, '-d', "$name=$value");
    }

    return $flags;
}

// The command line as the operator gave it, for a reload to start over from
if (false === \getenv('SWERVE_ARGS')) {
    \putenv('SWERVE_ARGS=' . \json_encode(phasync\ext\_original_args()));
}
if ('1' !== \getenv('SWERVE_INI_REEXEC')) {
    \putenv('SWERVE_INI_REEXEC=1');
    \pcntl_exec(\PHP_BINARY, [...swerve_ini_flags(), ...phasync\ext\_original_args()]);
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
// phasync.workers (the worker-parent model, see Cluster) needs the extension; the no-ext path
// (the plug alone, running every worker as this one process) isn't built yet.
if (!\extension_loaded('phasync')) {
    \fwrite(\STDERR, "swerve: ext-phasync is required for now: run with --ext (see `swerve --help`)\n");
    exit(1);
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
     * What every worker runs (see inc/lifecycle.php): ./swerve.php in the current directory;
     * `swerve some-app.php`, that single file; `-S [root] [router]`, a generated loop around
     * Swerve::accept($root, $router), as php -S's; bare, with neither, an error.
     */
    $cwd        = \getcwd();
    $swerveFile = \is_file("$cwd/swerve.php") ? "$cwd/swerve.php" : null;
    if ($args->serve) {
        if (null !== $swerveFile) {
            \fwrite(\STDERR, "swerve: -S and $swerveFile: configure the application there\n");
            exit(2);
        }
        $positional = \array_values(\array_filter([$args->path, $args->router], static fn ($a) => '' !== $a));
        $root       = null;
        $router     = null;
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
        if (false === $root || !\is_file($router)) {
            \fwrite(\STDERR, "swerve: $router not found: give the public root and the router (swerve -S [dir] [router.php])\n");
            exit(2);
        }
        // Every worker's inc/lifecycle.php requires this once per lifecycle: generated once here,
        // removed when the master exits (System::tempDirectory()'s cleanup).
        $appDir  = System::tempDirectory('swerve-serve');
        $appFile = "$appDir/app.php";
        \file_put_contents($appFile, '<?php while (true) { \Swerve\Swerve::accept(' . \var_export($root, true) . ', ' . \var_export($router, true) . "); }\n");
    } elseif (null !== $swerveFile) {
        if ('' !== $args->path) {
            \fwrite(\STDERR, "swerve: $swerveFile exists; configure the application there, or remove it to run {$args->path} instead\n");
            exit(2);
        }
        $appFile = $swerveFile;
    } elseif ('' !== $args->path) {
        if (!\is_file($args->path)) {
            \fwrite(\STDERR, "swerve: {$args->path} is not a file that exists (swerve some-app.php, swerve -S [dir] [router.php], or write a swerve.php)\n");
            exit(2);
        }
        $appFile = \realpath($args->path);
    } else {
        \fwrite(\STDERR, "swerve: no swerve.php here, and nothing on the command line: swerve some-app.php, or swerve -S [dir] [router.php]\n");
        exit(2);
    }

    $http = $args->noHttp ? [] : \array_map(Address::normalize(...), $args->http);
    if (\count(\array_unique($http)) < \count($http)) {
        $twice = \implode(' ', \array_unique(\array_diff_assoc($http, \array_unique($http))));
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
     * The HTTP listener(s), opened once here and inherited by every worker-parent generation
     * (see Cluster): one bound, listening socket per --http address, at descriptors
     * Cluster::LISTENER_FD, +1, ... in this same order; the ext forks the lifecycles onto it
     * (phasync.workers_listener), and the kernel spreads accept() across however many there
     * are (a classic pre-fork server) — no SO_REUSEPORT needed. --no-http: no listeners, and
     * Swerve::accept() throws in every lifecycle.
     */
    $listeners = [];
    foreach ($http as $address) {
        if (\str_starts_with($address, 'unix:')) {
            try {
                $listeners[] = System::listen($address);
            } catch (\RuntimeException $e) {
                $logger->critical('The server failed to start: {error}', ['error' => $e->getMessage()]);
                exit(1);
            }
            continue;
        }
        $listener = @\stream_socket_server("tcp://$address", $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN);
        if (false === $listener) {
            $logger->critical('The server failed to start: {address} is already in use ({error})', ['address' => $address, 'error' => $errstr]);
            exit(1);
        }
        \stream_set_blocking($listener, false);
        $listeners[] = $listener;
    }
    $listenerIni = [] === $listeners
        ? '-1'
        : \implode(',', \range(Cluster::LISTENER_FD, Cluster::LISTENER_FD + \count($listeners) - 1));

    /*
     * The worker-parent: a `php -d phasync.workers=N ... inc/lifecycle.php` process (the ext's C
     * parent), re-started fresh (a new generation) for a reload; same ini flags as this process
     * applied to itself above, so the lifecycles get the same memory_limit, opcache settings etc.
     * php.ini (shared by every `php` invocation here) already loads the extension when it is
     * registered there; only when THIS process got it by re-executing itself (--ext, above, which
     * leaves PHASYNC_EXT_REEXEC=1 behind) does the worker-parent need the same `-d extension=`,
     * since that flag lives only in the re-exec'd argv, not in php.ini.
     */
    $extFlags = '1' === \getenv('PHASYNC_EXT_REEXEC') && \is_string($so = \phasync\ext\_resolve_so())
        ? ['-d', "extension=$so"]
        : [];
    $workerParentArgv = [
        \PHP_BINARY,
        ...$extFlags,
        ...swerve_ini_flags(),
        '-d', "phasync.workers=$workerCount",
        '-d', "phasync.workers_listener=$listenerIni",
        __DIR__ . '/../inc/lifecycle.php',
    ];
    $workerParentEnv = \getenv() + [
        'SWERVE_ARGS'     => (string) \getenv('SWERVE_ARGS'),
        'SWERVE_APP_FILE' => $appFile,
    ];

    /*
     * The master process starts the worker-parent, and supervises it (and reloads) until stopped.
     */
    $cluster = new Cluster(
        $workerCount,
        $logger,
        (float) $args->grace,
        (float) $args->linger,
        (float) $args->watchdog,
        $args->watch ? \dirname($appFile) : null,
        \sprintf('swerve %s serving %s on %s with %d worker%s%s', Swerve::getVersion(),
            $swerveFile ?? ($args->serve ? "$root through $router" : $appFile),
            [] === $http ? 'no HTTP listener' : \implode(', ', \array_map(static fn ($a) => \str_starts_with($a, 'unix:') ? 'http+unix://' . \substr($a, 5) : "http://$a", $http)),
            $workerCount, 1 === $workerCount ? '' : 's', $args->watch ? ', reloading when PHP files change' : ''),
        $workerParentArgv,
        $workerParentEnv,
        $listeners,
        (int) \ini_parse_quantity($args->cacheSize),
    );

    $exitCode = $cluster->run();
    foreach ($http as $address) {
        if (\str_starts_with($address, 'unix:')) {
            System::unlinkSocket($address);
        }
    }
    exit($exitCode);
})();
