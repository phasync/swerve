<?php

/*
 * Find the path to `vendor/autoload.php`.
 */

use Charm\Terminal;
use phasync\Debug;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\CLI\Args;
use Swerve\FastCGI\FastCGIServer;
use Swerve\HAProxy;
use Swerve\Http\NativeHttpServer;
use Swerve\Runners\Psr15Runner;
use Swerve\Swerve;
use Swerve\SwerveInterface;
use Swerve\Util\Cluster;
use Swerve\Util\Logger;
use Swerve\Util\System;

// Composer's vendor/bin proxy says where the autoloader is. Otherwise: swerve's own vendor
// directory in a checkout, or the project's when installed at vendor/phasync/swerve.
require $GLOBALS['_composer_autoload_path']
    ?? (\is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../autoload.php');

pcntl_async_signals(true);

/**
 * Ensure we don't pollute the global namespace.
 */
(function () use ($argv) {
    /**
     * Colorful terminal.
     */
    $term = new Terminal(\STDOUT);

    /**
     * Argument parsing.
     *
     * @var Args
     */
    $args = require __DIR__.'/../inc/args.php';

    /*
    * Banner
    */
    if (!$args->quiet) {
        $term->write("<!bold>[ SWERVE ] <!blue>Swerving your website...<!!>\n\n");
    }
    $term->write("<!red> >>> <!bold>WARNING! THIS IS BETA SOFTWARE FOR PREVIEW ONLY<!> <<<<!>\n\n");

    /*
    * Validate arguments or display --help
    */
    if ($error = $args->isInvalid()) {
        $term->write("<!redBG white>ERROR:<!> <!bold>$error<!>\n\n");
        $term->write("Valid arguments:\n");
        $term->write($args->getArgumentList()."\n");
        exit(255);
    } elseif ($args->help) {
        $term->write('<!yellow>Usage:<!> <!underline>'.\basename($argv[0]).'<!> '.$args->getShortArgumentList()."\n\n");
        $term->write($args->getArgumentList()."\n");
        exit(255);
    }

    phasync::setDefaultTimeout(60);

    /**
     * Check that `swerve.php` file exists and load the application.
     */
    $swerveFile = \realpath($args->swervefile);
    if ($swerveFile === false) {
        $term->write("<!redBG white>ERROR:<!> <!bold>{$args->swervefile} not found<!>\n\n");
        exit(1);
    }
    $app = require $swerveFile;

    /*
    * Select the runner function
    */
    if ($app instanceof SwerveInterface) {
        $runner = $app;
    } elseif ($app instanceof RequestHandlerInterface) {
        $runner = new Psr15Runner($app);
    } else {
        $term->write("<!redBG white>ERROR:<!> <!bold>{$args->swervefile} returned ".Debug::getDebugInfo($app)."<!>\n\n");
        exit(2);
    }

    /*
     * Argument verbosity
     */
    if ($args->verbosity >= 3) {
        $logLevel = LogLevel::DEBUG;
    } elseif ($args->verbosity == 2) {
        $logLevel = LogLevel::INFO;
    } elseif ($args->verbosity == 1) {
        $logLevel = LogLevel::NOTICE;
    } else {
        $logLevel = LogLevel::ERROR;
    }

    /**
     * Main process or child process keeps running while this is true.
     */
    $keepRunning = true;

    /*
     * Logger Interface
     */
    if ($args->quiet) {
        $logger = new NullLogger();
    } else {
        $logger = new Logger($term, logLevel: $logLevel);
    }

    /*
     * Setup clustering to launch enough worker processes.
     */
    if ($args->workers === 'auto') {
        // In web server mode HAProxy needs cores too: measured best on 56 cores was 32
        // workers and 16 HAProxy threads, with wrk on another machine
        $workerCount = empty($args->fastcgi) && empty($args->nativehttp)
            ? \max(1, \intdiv(System::getCPUCount() * 4, 7))
            : System::getCPUCount();
    } else {
        $workerCount = $args->workers;
    }
    $cluster = new Cluster($workerCount, $logger);
    $logger->info('Will launch {workerCount} workers', ['workerCount' => $workerCount]);

    /**
     * True if the current process is a worker process.
     */
    $isWorker = false;

    /**
     * Callbacks to invoke at start of worker coroutine.
     *
     * @var Closure[]
     */
    $workerCallbacks = [];

    /**
     * Callbacks to invoke for master process after forking workers.
     *
     * @var Closure[]
     */
    $masterCallbacks = [];

    /**
     * Signal handler function.
     */
    $signalHandler = function ($signo) use ($logger, &$keepRunning, &$isWorker) {
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                if ($isWorker) {
                    /**
                     * @todo More graceful termination
                     */
                    $keepRunning = false;
                    exit(0);
                }
                $logger->notice('Signal received, stopping workers.');
                $keepRunning = false;
        }
    };

    /**
     * Setup the Swerve server according to commandline args.
     */
    $swerve = new Swerve($logger);

    if (!empty($args->nativehttp)) {
        /*
         * Native HTTP mode
         *
         * Every worker serves HTTP/1.1 itself on the same address (SO_REUSEPORT); no HAProxy.
         */
        if (!empty($args->fastcgi) || !$args->isDefault('http') || !$args->isDefault('https')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't combine --native-http with --fastcgi, --http or --https<!>\n\n");
            exit(2);
        }
        if (!$app instanceof RequestHandlerInterface) {
            $term->write("<!redBG white>ERROR:<!> <!bold>--native-http needs {$args->swervefile} to return a PSR-15 RequestHandlerInterface<!>\n\n");
            exit(2);
        }
        $workerCallbacks[] = function () use ($args, $app, $logger) {
            foreach ($args->nativehttp as $address) {
                $server = new NativeHttpServer($address, $app, $logger);
                phasync::go($server->run(...));
            }
        };
        $masterCallbacks[] = function () use ($args, $logger) {
            $logger->alert('Listening to {http}', ['http' => \implode(' ', $args->nativehttp)]);
        };
    } elseif (!empty($args->fastcgi)) {
        /*
         * FastCGI mode
         *
         * This mode is for running Swerve behind another web server like
         * for example nginx.
         */
        if (!$args->isDefault('http') || !$args->isDefault('https')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't combine --fastcgi with --http or --https<!>\n\n");
            exit(2);
        }
        foreach ($args->fastcgi as $fastcgi) {
            $logger->debug('FastCGI server at {address}', ['address' => $fastcgi]);
            [$ip, $port] = \explode(':', $fastcgi);
            $swerve->add(new FastCGIServer("tcp://$ip:$port", $logger));
        }
    } else {
        /**
         * Web server mode.
         *
         * HAProxy listens for HTTP and passes requests to the workers over multiplexed
         * FastCGI, one Unix socket per worker slot.
         */
        if (!$args->isDefault('https')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>--https is not supported yet<!>\n\n");
            exit(2);
        }
        $socketDir = \sys_get_temp_dir().'/swerve-'.\posix_getpid();
        if (!\is_dir($socketDir) && !\mkdir($socketDir, 0700)) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't create $socketDir<!>\n\n");
            exit(1);
        }
        $socketPath = static fn (int $slot): string => "$socketDir/worker$slot.sock";

        $workerCallbacks[] = function () use ($swerve, $logger, $cluster, $socketPath) {
            $path = $socketPath($cluster->getSlot());
            if (\file_exists($path)) {
                // Left behind by the worker this one replaces
                \unlink($path);
            }
            $logger->debug('Worker listening on {path}', ['path' => $path]);
            $swerve->add(new FastCGIServer('unix://'.$path, $logger));
        };

        $masterCallbacks[] = function () use (&$keepRunning, $cluster, $socketPath, $socketDir, $args, $logger) {
            $sockets = \array_map($socketPath, \range(0, $cluster->getWorkerCount() - 1));
            // One HAProxy thread per two workers; more than 16 was slower (each thread keeps
            // its own FastCGI connections, so fewer requests share a connection)
            $threads = (int) (\getenv('SWERVE_HAPROXY_THREADS') ?: \min(16, \max(1, \intdiv($cluster->getWorkerCount(), 2))));
            $haproxy = new HAProxy($args->http, $sockets, $logger, $threads);
            $haproxy->start(function () use (&$keepRunning, $logger) {
                if ($keepRunning) {
                    // Not stopped by us, for example a Ctrl+C that reached HAProxy too
                    $logger->critical('HAProxy exited, stopping');
                    $keepRunning = false;
                }
            });
            $logger->alert('Listening to {http}', ['http' => \implode(' ', $args->http)]);

            phasync::go(function () use (&$keepRunning, $haproxy, $socketDir) {
                while ($keepRunning) {
                    phasync::sleep(0.5);
                }
                $haproxy->stop();
                foreach (\glob("$socketDir/*.sock") ?: [] as $socket) {
                    \unlink($socket);
                }
                @\rmdir($socketDir);
            });
        };
    }

    if ($cluster->launch()) {
        pcntl_signal(SIGTERM, $signalHandler);
        pcntl_signal(SIGINT, $signalHandler);
        $logger->notice('Workers are being monitored');
        phasync::run(function () use ($cluster, &$keepRunning, &$isWorker, &$masterCallbacks) {
            foreach ($masterCallbacks as $callback) {
                $callback();
            }

            phasync::go(function () use ($cluster, &$keepRunning, &$isWorker) {
                while ($keepRunning) {
                    if ($cluster->relaunchChildren()) {
                        $isWorker = true;

                        return;
                    }
                    phasync::sleep(0.5);
                }
                $cluster->stop();
            });
        });

        if (!$isWorker) {
            $cluster->stop();
            $logger->notice('SWERVE stopped...');
            exit(0);
        }
    }

    phasync::run(function () use ($swerve, $runner, $workerCallbacks) {
        foreach ($workerCallbacks as $callback) {
            $callback();
        }

        $swerve->run($runner);
    });
})();
