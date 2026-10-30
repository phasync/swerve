<?php

/*
 * A swerve WebSocket worker, forked from the master as WebSockets need one: it loads its bootstrap
 * file, then serves the WebSockets that Swerve::upgrade() moved here with that
 * file (Swerve\Lot\WebSocketWorker), until the master says stop.
 */

(static function (): void {
    \pcntl_signal(\SIGINT, \SIG_IGN); // Ctrl+C reaches every process of the terminal's group: the master decides
    require \getenv('SWERVE_AUTOLOAD');
    phasync::run(Swerve\Lot\WebSocketWorker::serve(...));
})();
