<?php // a request that session_start()s, sets a value, waits with phasync::sleep(), then reads
// $_SESSION back: reading it after the wait crashes the worker (SIGSEGV) in RC3; see
// ConcurrentModeTest.php

use Swerve\Swerve;

session_save_path(sys_get_temp_dir());

while (Swerve::accept(null, function () {
    if ('/hello' === $_SERVER['REQUEST_URI']) {
        echo 'hello'; // used only to probe that the worker is up

        return;
    }
    session_start();
    $_SESSION['last'] = $_GET['n'] ?? '0';
    phasync::sleep(0.02);
    $seen = $_SESSION['last'];
    echo "ok $seen\n";
    session_write_close();
})) {
}
