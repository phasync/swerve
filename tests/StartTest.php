<?php

/*
 * How swerve starts: through phasync's bin/phasync, which loads the extension with its keywords on.
 */

test('an extension loaded without phasync.keywords makes swerve say so and exit 1, instead of starting over forever (#94)', function () {
    $process  = swerve_spawn(['--http=' . free_address(), '--workers=1'], 'app.php', php: ['-d', 'phasync.keywords=0'], out: [2 => ['pipe', 'w']]);
    $deadline = microtime(true) + 10;
    while (proc_get_status($process)['running']) {
        expect(microtime(true))->toBeLessThan($deadline, 'swerve is still running');
        usleep(20000);
    }
    $status = proc_get_status($process)['exitcode'];

    expect($status)->toBe(1);
});
