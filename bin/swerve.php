<?php

/*
 * Find the path to `vendor/autoload.php`.
 */

use Charm\Terminal;
use phasync\Debug;
use phasync\Process\Process;
use phasync\Process\ProcessInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\CLI\Args;
use Swerve\FastCGI\FastCGIServer;
use Swerve\Runners\Psr15Runner;
use Swerve\Swerve;
use Swerve\SwerveInterface;
use Swerve\Util\Cluster;
use Swerve\Util\Logger;
use Swerve\Util\System;

require realpath(__DIR__.'/../../../vendor/autoload.php') ?: realpath(__DIR__.'/../vendor/autoload.php') ?: 'vendor/autoload.php';
require __DIR__.'/../inc/caddy.php';

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
         * Web Server mode.
         *
         * This mode launches workers using unix domain socket files, and configures
         * the web server to load balance between the worker processes.
         */
        $unixSocket = tempnam(\sys_get_temp_dir(), 'swerve-');
        unlink($unixSocket);

        /*
         * Configure each worker to have a custom socket file name
         * based on their PID number and ensure the socket file is
         * deleted when the process terminates.
         */
        $workerCallbacks[] = function () use ($swerve, $logger, $unixSocket) {
            $filename = "$unixSocket.".posix_getpid().'.sock';
            $logger->debug('Worker listening on {filename}', ['filename' => $filename]);
            $swerve->add(new FastCGIServer('unix://'.$filename, $logger));
            register_shutdown_function(function () use ($filename) {
                unlink($filename);
            });
        };

        /*
         * Setup Caddy to be launched by the master process
         */
        $masterCallbacks[] = function () use (&$keepRunning, $cluster, $unixSocket, $args, $logger) {
            phasync::go(function () use (&$keepRunning, $cluster, $unixSocket, $args, $logger) {
                $configFile = tempnam(sys_get_temp_dir(), 'caddy-');
                register_shutdown_function(function () use ($configFile) {
                    unlink($configFile);
                });
                $oldConfig = null;
                $process = null;

                while ($keepRunning) {
                    /**
                     * Update the list of unix domain sockets.
                     */
                    $unixSockets = [];
                    foreach ($cluster->getWorkerPids() as $pid) {
                        $unixSockets[] = $unixSocket.'.'.$pid.'.sock';
                    }

                    /**
                     * Build a new config and update the config file if the config
                     * differs. Caddy will automatically reload the configuration.
                     */
                    $config = buildCaddyConfig($unixSockets, $args->http, $args->https);
                    if (serialize($config) !== serialize($oldConfig)) {
                        $oldConfig = $config;
                        file_put_contents($configFile, json_encode($config));
                    }

                    if ($process === null) {
                        $process = Process::run(__DIR__.'/../t/caddy', ['run', '--config', $configFile, '--watch']);
                        phasync::go(function () use ($process, &$keepRunning, $logger) {
                            $buffer = '';
                            while ($keepRunning) {
                                $chunk = $process->read(ProcessInterface::STDERR);
                                $buffer .= $chunk;
                                [$line, $buffer] = explode("\n", $buffer, 2);
                                $data = \json_decode($line, true);
                                $level = $data['level'];
                                $msg = $data['msg'];
                                $logger->info('caddy: {msg}', ['msg' => $msg]);
                            }
                        });
                    }

                    phasync::sleep(0.5);
                }
                $process->stop();
            });
        };
    }

    function caddy_cmd()
    {
        global $cluster, $unixSocket, $args;
        $unixSockets = [];
        foreach ($cluster->getWorkerPids() as $pid) {
            $unixSockets[] = $unixSocket.'.'.$pid.'.sock';
        }
        $config = buildCaddyConfig($unixSockets, $args->http, $args->https);
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
