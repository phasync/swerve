<?php

/*
 * A swerve worker's lifecycle: the master starts `php inc/worker.php` once per worker, and
 * run_next() runs this file again, as a fresh request in the same process, each time the
 * application's swerve.php ends. The connection channel to the master (fd 3) stays open. A
 * worker told to stop ends its lifecycle, and the next one exits at its hello, without a next.
 *
 * No variables at this file's top level: a script handler runs its file in the global scope
 * (include_global()), which must not share one with code still running there.
 */

(static function (): void {
    \pcntl_signal(\SIGINT, \SIG_IGN); // Ctrl+C reaches every process of the terminal's group: the master decides
    require \getenv('SWERVE_AUTOLOAD');
    // The lifecycle ends once swerve.php and every coroutine it started have ended
    phasync::run(static function (): void {
        if (!Swerve\Lot\LotClient::get()->hello()) {
            return;
        }
        phasync\ext\run_next(__FILE__);
        try {
            require \getenv('SWERVE_APP');
        } catch (Swerve\WorkerStoppingException) {
            // accept() said the worker stops: the end swerve.php expects
        }
        Swerve\Lot\LotClient::get()->drain();
    });
})();
