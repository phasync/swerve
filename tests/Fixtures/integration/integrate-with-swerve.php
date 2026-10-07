<?php

use Swerve\Swerve;

Swerve::ini(['memory_limit' => '1G']);
Swerve::onWorkerStart(static function () {
    $GLOBALS['fx_worker_started'] = 'yes';
});
