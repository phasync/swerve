<?php

/*
 * A swerve WebSocket worker, forked from the master as WebSockets need one: it serves those that
 * short-lived lifecycles moved here (Swerve\Lot\WebSocketWorker), until the master says stop.
 */

(static function (): void {
    \pcntl_signal(\SIGINT, \SIG_IGN); // Ctrl+C reaches every process of the terminal's group: the master decides
    require \getenv('SWERVE_AUTOLOAD');
    phasync::run(Swerve\Lot\WebSocketWorker::serve(...));
})();
