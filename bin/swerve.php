<?php

/*
 * Find the path to `vendor/autoload.php`.
 */

use Charm\Terminal;
use phasync\Debug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\CLI\Args;
use Swerve\Connection;
use Swerve\ConnectionInterface;
use Swerve\FastCGI\FastCGIServer;
use Swerve\Http\NativeHttpServer;
use Swerve\Runners\Psr15Runner;
use Swerve\Swerve;
use Swerve\SwerveInterface;
use Swerve\Util\Cluster;
use Swerve\Util\Logger;
use Swerve\Util\LoggingContext;
use Swerve\Util\System;
use Swerve\Util\Worker;

// Composer's vendor/bin proxy says where the autoloader is. Otherwise: swerve's own vendor
// directory in a checkout, or the project's when installed at vendor/phasync/swerve.
require $GLOBALS['_composer_autoload_path']
    ?? (\is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../autoload.php');

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
        $term->write("<!red> >>> <!bold>WARNING! THIS IS BETA SOFTWARE FOR PREVIEW ONLY<!> <<<<!>\n\n");
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
        exit(0);
    }

    phasync::setDefaultTimeout(60);

    /**
     * Check that `swerve.php` file exists. The application is loaded in each worker, after
     * the fork, so that a reload runs the current code. Symlinks are not resolved: a deploy
     * that points `current` at a new release, then reloads, runs the new release.
     */
    $swerveFile = \str_starts_with($args->swervefile, '/') ? $args->swervefile : \getcwd() . '/' . $args->swervefile;
    if (!\is_file($swerveFile)) {
        $term->write("<!redBG white>ERROR:<!> <!bold>{$args->swervefile} not found<!>\n\n");
        exit(1);
    }

    $addresses = $args->fastcgi ?: $args->http;
    if (\count(\array_unique($addresses)) < \count($addresses)) {
        $twice = \implode(' ', \array_unique(\array_diff_assoc($addresses, \array_unique($addresses))));
        $term->write("<!redBG white>ERROR:<!> <!bold>$twice more than once<!>\n\n");
        exit(2);
    }
    if (!empty($args->fastcgi)) {
        if (!$args->isDefault('http')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't combine --fastcgi with --http<!>\n\n");
            exit(2);
        }
        if ($args->bufferResponses || !$args->isDefault('maxBody')) {
            $term->write("<!redBG white>ERROR:<!> <!bold>--buffer-responses and --max-body only apply to --http<!>\n\n");
            exit(2);
        }
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

    /*
     * Logger Interface. With --log, PHP's own errors go to the file too: after a fatal error
     * such as memory_limit, PHP still writes its message, where a handler of ours may have no
     * memory left to run.
     */
    if ($args->log) {
        $file = @\fopen($args->log, 'a');
        if (false === $file) {
            $term->write("<!redBG white>ERROR:<!> <!bold>Can't open {$args->log} for writing<!>\n\n");
            exit(1);
        }
        \ini_set('log_errors', '1');
        \ini_set('error_log', $args->log);
        \ini_set('display_errors', '0');
        $logger = new Logger($file, 'master', $logLevel, $args->log);
    } elseif ($args->quiet) {
        \ini_set('display_errors', '0');
        \ini_set('log_errors', '0');
        $logger = new NullLogger();
    } else {
        $logger = new Logger(\STDOUT, 'master', $logLevel);
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
    foreach ($args->fastcgi ?: $args->http as $address) {
        $probe = @\stream_socket_server("tcp://$address", $errno, $errstr, \STREAM_SERVER_BIND);
        if (false === $probe) {
            $logger->critical('The server failed to start: {address} is already in use ({error})', ['address' => $address, 'error' => $errstr]);
            exit(1);
        }
        \fclose($probe);
    }

    /*
     * Setup clustering to launch enough worker processes.
     */
    if ($args->workers === 'auto') {
        $workerCount = System::getCPUCount();
    } else {
        $workerCount = (int) $args->workers;
    }
    $cluster = new Cluster(
        $workerCount,
        $logger,
        (float) $args->grace,
        (float) $args->watchdog,
        $args->monitor ? \dirname($swerveFile) : null,
        \implode(' ', $args->fastcgi ?: $args->http),
    );
    $worker = $cluster->run();
    if (\is_int($worker)) {
        exit($worker);
    }

    /*
     * From here on: a worker process. Load the application.
     */
    $logger = $worker->logger;
    Worker::refreshAutoloader();
    try {
        $app = require $swerveFile;
    } catch (\Throwable $e) {
        $logger->critical('Loading {file} failed: {exception}', ['file' => $swerveFile, 'exception' => $e]);
        exit(Worker::EXIT_BAD_APP);
    }
    if ($app instanceof SwerveInterface) {
        $runner = $app;
    } elseif ($app instanceof RequestHandlerInterface) {
        $runner = new Psr15Runner($app);
    } else {
        $logger->critical('{file} returned {value}', ['file' => $args->swervefile, 'value' => Debug::getDebugInfo($app)]);
        exit(Worker::EXIT_BAD_APP);
    }
    if (empty($args->fastcgi) && !$app instanceof RequestHandlerInterface) {
        $logger->critical('HTTP mode needs {file} to return a PSR-15 RequestHandlerInterface', ['file' => $args->swervefile]);
        exit(Worker::EXIT_BAD_APP);
    }
    $worker->setLimits($args->maxMemory, (int) $args->maxRequests);

    if (!empty($args->fastcgi)) {
        /*
         * FastCGI mode
         *
         * For running swerve behind another web server, such as nginx, which speaks FastCGI
         * to the workers.
         */
        $swerve = new Swerve($logger);
        foreach ($args->fastcgi as $fastcgi) {
            $swerve->add(new FastCGIServer("tcp://$fastcgi", $logger));
        }
        // Every request counts towards recycling, also one that throws
        $runner = new class($runner, $worker) implements SwerveInterface {
            public function __construct(private SwerveInterface $inner, private Worker $worker)
            {
            }

            public function handleConnection(ConnectionInterface $connection): void
            {
                // The request's head may not have come yet
                $id = $this->worker->requestStarted(static fn () => $connection->getState() > Connection::STATE_REQUEST_HEAD
                    ? $connection->getRequestMethod() . ' ' . $connection->getRequestTarget()
                    : 'a request');
                try {
                    $this->inner->handleConnection($connection);
                } finally {
                    $this->worker->requestDone($id);
                }
            }
        };
        phasync::run(static function () use ($worker, $swerve, $runner, $logger) {
            $worker->serve(static fn () => $swerve->run($runner, $worker->ready(...), new LoggingContext($logger)), $swerve->stop(...));
        }, [], new LoggingContext($logger));
    } else {
        /*
         * HTTP mode
         *
         * Every worker serves HTTP/1.1 itself on the same address (SO_REUSEPORT), and the
         * kernel spreads new connections over them.
         */
        $handler = new class($app, $worker) implements RequestHandlerInterface {
            public function __construct(private RequestHandlerInterface $app, private Worker $worker)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $id = $this->worker->requestStarted(static fn () => $request->getMethod() . ' ' . $request->getRequestTarget());
                try {
                    return $this->app->handle($request);
                } finally {
                    $this->worker->requestDone($id);
                }
            }
        };
        $maxBody = (int) $args->maxBody ?: \PHP_INT_MAX;
        $servers = [];
        foreach ($args->http as $address) {
            $server = new NativeHttpServer($address, $handler, $logger, (bool) $args->bufferResponses, $maxBody);
            try {
                $server->listen();
            } catch (\Throwable $e) {
                // Before 'R': the master counts it as a failed start
                $logger->critical('{exception}', ['exception' => $e]);
                exit(1);
            }
            $servers[] = $server;
        }
        phasync::run(static function () use ($worker, $servers) {
            foreach ($servers as $server) {
                $worker->serve($server->run(...), $server->drain(...));
            }
            $worker->ready();
        }, [], new LoggingContext($logger));
    }
    exit(0);
})();
