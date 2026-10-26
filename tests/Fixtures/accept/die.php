<?php
// The FPM-like shape with a handler that ends with die(): the lifecycle ends, the response must not
$run = (int) @file_get_contents(sys_get_temp_dir() . '/swerve-die-runs-' . getmypid()) + 1;
file_put_contents(sys_get_temp_dir() . '/swerve-die-runs-' . getmypid(), (string) $run);
phasync::await(Swerve\Swerve::accept(null, function () use ($run) {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/early')) {
        header('X-App: early');
        http_response_code(404);
        die();
    }
    ob_start();
    echo "Hello, World run $run\n";
    die();
}));
