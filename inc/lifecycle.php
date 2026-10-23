<?php

/*
 * One lifecycle of one worker slot of phasync.workers (see Cluster, PLAN.md). The C parent runs
 * this file again, fresh (globals cleared), for the next lifecycle in the same process, until
 * this slot is told to stop; a lifecycle that ends by a plain PHP exit() (an error, or
 * Worker::stop()) ends the process instead, and the parent replaces it. Argv: the application
 * file (a swerve.php, a generated -S router loop, or the single file `swerve some-app.php` runs).
 */

use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Swerve\CLI\Args;
use Swerve\Http\TrustedProxies;
use Swerve\Swerve;
use Swerve\Util\Inboxes;
use Swerve\Util\Logger;
use Swerve\Util\Plug;
use Swerve\Util\System;
use Swerve\Util\Worker;

// Same autoloading as bin/swerve.php's own (see WorkerAutoloader): the application's classes and
// Composer "files" load fresh with its own require of vendor/autoload.php, as under PHP-FPM.
require_once __DIR__ . '/../src/Util/WorkerAutoloader.php';
require_once __DIR__ . '/../src/Util/Plug.php';
$vendorDir = isset($GLOBALS['_composer_autoload_path'])
    ? \dirname($GLOBALS['_composer_autoload_path'])
    : (\is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor' : \dirname(__DIR__, 3));
$rootInstallPath = \is_file("$vendorDir/composer/installed.php")
    ? ((require "$vendorDir/composer/installed.php")['root']['install_path'] ?? \getcwd())
    : \getcwd();
Plug::installIfNeeded($vendorDir, $rootInstallPath);
if (isset($GLOBALS['_composer_autoload_path']) && \Swerve\Util\WorkerAutoloader::applies($vendorDir)) {
    \Swerve\Util\WorkerAutoloader::register($vendorDir, $rootInstallPath);
} else {
    $autoload = $GLOBALS['_composer_autoload_path']
        ?? (\is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../../autoload.php');
    require Plug::path($autoload, \dirname($autoload));
}
unset($vendorDir, $rootInstallPath);

// The command line as the operator gave it to the master (not this process's own argv, which
// the ext sets to the application file alone): rebuilt through the same spec as bin/swerve.php's.
$_SERVER['argv'] = ['lifecycle', ...\json_decode((string) \getenv('SWERVE_ARGS'), true)];
$args = require __DIR__ . '/args.php';

$slot       = (int) \getenv('PHASYNC_WORKER');
$generation = (int) \getenv('SWERVE_GENERATION');
$grace      = (float) $args->grace;
$linger     = (float) $args->linger;
$watchdog   = (float) $args->watchdog;

// Hello: this slot, our pid and the worker-parent's generation (12 bytes, big-endian); the
// master answers with our inbox number and a failed-start backoff delay (see Cluster::readHello()).
$master = \stream_socket_client('unix://' . \getenv('SWERVE_MASTER'), $errno, $errstr, 10);
if (false === $master) {
    \fwrite(\STDERR, "lifecycle: could not connect to the master: $errstr\n");
    exit(1);
}
\fwrite($master, \pack('NNN', $slot, \getmypid(), $generation));
$hello = '';
while (\strlen($hello) < 12) {
    $hello .= \fread($master, 12 - \strlen($hello));
}
['inbox' => $inbox, 'delay' => $delay] = \unpack('Ninbox/ddelay', $hello);
if ($delay > 0) {
    \usleep((int) ($delay * 1_000_000));
}

[$ownInbox, $peers] = Inboxes::adoptFromFds($inbox, (int) \getenv('SWERVE_INBOX_FD_BASE'), (int) \getenv('SWERVE_INBOX_COUNT'));
System::pinToNumaNode($slot);

// The column after the time: the slot number, right-aligned as the master's logger left it blank for
$source = \str_repeat(' ', \strlen((string) \max(0, $slot)));
$logLevel = $args->verbosity >= 2 ? LogLevel::DEBUG : ($args->verbosity == 1 ? LogLevel::INFO : LogLevel::NOTICE);
if ($args->log) {
    $logger = new Logger(\fopen($args->log, 'a'), $source, $logLevel, $args->log, !$args->noAccessLog);
} elseif ($args->quiet) {
    $logger = new NullLogger();
} else {
    $logger = new Logger(\STDOUT, $source, $logLevel, access: !$args->noAccessLog);
}

$worker = new Worker($slot, $master, $grace, $linger, $watchdog, $logger, $inbox, $ownInbox, $peers);

phasync::run(static function () use ($worker, $args, $logger): void {
    phasync::frame()->setExceptionHandler(static fn (\Throwable $e) => $logger->error('Unhandled exception in a coroutine: {exception}', ['exception' => $e]));
    Swerve::setLog($logger);
    Worker::refreshAutoloader();
    try {
        Swerve::startWorker();
    } catch (\Throwable $e) {
        $logger->critical('A Swerve::onWorkerStart() callback failed: {exception}', ['exception' => $e]);
        exit(Worker::EXIT_BAD_APP);
    }
    $worker->setLimits($args->maxMemory, (int) $args->maxRequests);
    $proxies = $args->trustedProxy ? new TrustedProxies($args->trustedProxy) : null;
    Swerve::configureHttp((int) $args->maxBody ?: \PHP_INT_MAX, $proxies, $logger);

    $appFile = (string) \getenv('SWERVE_APP_FILE');
    if ('' === $appFile) {
        $logger->critical('No application file (swerve some-app.php, or swerve.php): see `swerve --help`');
        exit(Worker::EXIT_BAD_APP);
    }
    if ($args->noHttp) {
        // No listener to wait on: "ready" is simply the script now running, see PLAN.md.
        $worker->ready();
    }
    $worker->serve(
        static function () use ($appFile): void {
            \chdir(\dirname($appFile));
            require Plug::path($appFile, \dirname($appFile));
        },
        // Nothing to signal here: accept()'s own suspensions (worker_next(), the request queue's
        // flag) get the WorkerStoppingException like every other coroutine, and unwind the
        // application's own accept() loop; see Worker::drain(). An HTTP application's first
        // accept() call sends 'R' itself (Swerve::accept()).
        static function (bool $linger): void {
        },
    );
});
