<?php
// The WordPress shape: one accept() per run of this script. Static files don't use it up.
$run = (int) @file_get_contents(sys_get_temp_dir() . '/swerve-static-runs-' . getmypid()) + 1;
file_put_contents(sys_get_temp_dir() . '/swerve-static-runs-' . getmypid(), (string) $run);
phasync::await(Swerve\Swerve::accept(__DIR__ . '/static', function () use ($run) {
    echo "php run $run";
}));
