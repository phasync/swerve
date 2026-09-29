<?php
// swerve: the Slim App is the PSR-15 handler. /usleep: usleep() with phasync-ext (suspends only
// this request's coroutine), phasync::sleep() without it.
require __DIR__ . '/vendor/autoload.php';

return slim_app(\extension_loaded('phasync') ? null : static fn (float $s) => \phasync::sleep($s));
