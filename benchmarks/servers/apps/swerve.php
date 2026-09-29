<?php
// swerve: its own PSR-7 request in, the shared handler's nyholm response out. /wait: usleep() with
// phasync-ext (suspends only this request's coroutine), phasync\sleep() without it.
require __DIR__ . '/vendor/autoload.php';

return new Handler(\extension_loaded('phasync') ? null : static fn () => \phasync\sleep(0.01));
