<?php

/*
 * swerve's WebSocket worker, forked from the master: it serves the WebSockets that short-lived
 * lifecycles moved here (Swerve\Lot\WebSocketWorker), until the master is gone.
 */

(static function (): void {
    require \getenv('SWERVE_AUTOLOAD');
    phasync::run(Swerve\Lot\WebSocketWorker::serve(...));
})();
