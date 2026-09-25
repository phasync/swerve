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
        $workerCount = System::getCPUCount();
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

    if (!empty($args->fastcgi)) {
        /*
         * FastCGI mode
         *
         * For running swerve behind another web server, such as nginx, which speaks FastCGI
         * to the workers.
         */
        if (!$args->isDefault('http')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't combine --fastcgi with --http<!>\n\n");
            exit(2);
        }
        foreach ($args->fastcgi as $fastcgi) {
            $logger->debug('FastCGI server at {address}', ['address' => $fastcgi]);
            [$ip, $port] = \explode(':', $fastcgi);
            $swerve->add(new FastCGIServer("tcp://$ip:$port", $logger));
        }
    } else {
        /*
         * HTTP mode
         *
         * Every worker serves HTTP/1.1 itself on the same address (SO_REUSEPORT), and the
         * kernel spreads new connections over them.
         */
        if (!$app instanceof RequestHandlerInterface) {
            $term->write("<!redBG white>ERROR:<!> <!bold>HTTP mode needs {$args->swervefile} to return a PSR-15 RequestHandlerInterface<!>\n\n");
            exit(2);
        }
        $workerCallbacks[] = function () use ($args, $app, $logger) {
            foreach ($args->http as $address) {
                $server = new NativeHttpServer($address, $app, $logger);
                phasync::go($server->run(...));
            }
        };
        $masterCallbacks[] = function () use ($args, $logger) {
            $logger->alert('Listening to {http}', ['http' => \implode(' ', $args->http)]);
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
