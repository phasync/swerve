<?php

/*
 * A swerve worker's lifecycle: the master starts `php inc/worker.php` once per worker, and
 * run_next() runs this file again, as a fresh request in the same process, each time the
 * application's swerve.php ends. The connection channel to the master (fd 3) stays open.
 *
 * No variables at this file's top level: a script handler runs its file in the global scope
 * (include_global()), which must not share one with code still running there.
 */

phasync\ext\run_next(__FILE__);

(static function (): void {
    require \getenv('SWERVE_AUTOLOAD');
    Swerve\Swerve::setLog(new Swerve\Util\Logger(\STDERR, (string) \getmypid(), Psr\Log\LogLevel::NOTICE));
    // The lifecycle ends once swerve.php and every coroutine it started have ended
    phasync::run(static function (): void {
        Swerve\Swerve::startWorker();
        require \getenv('SWERVE_APP');
    });
})();
