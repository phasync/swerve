<?php

/*
 * A swerve WebSocket worker, forked from the master as WebSockets need one: it serves those that
 * short-lived lifecycles moved here (Swerve\Lot\WebSocketWorker), until the master is gone.
 */

(static function (): void {
    require \getenv('SWERVE_AUTOLOAD');
    phasync::run(Swerve\Lot\WebSocketWorker::serve(...));
})();
