<?php

/*
 * swerve's WebSocket worker, forked from the master: it serves the WebSockets that short-lived
 * lifecycles moved here (Swerve\Lot\WebSocketWorker), until the master is gone.
 */

(static function (): void {
    require \getenv('SWERVE_AUTOLOAD');
    Swerve\Swerve::setLog(new Swerve\Util\Logger(\STDERR, \getmypid() . ' websockets', Psr\Log\LogLevel::NOTICE));
    phasync::run(Swerve\Lot\WebSocketWorker::serve(...));
})();
