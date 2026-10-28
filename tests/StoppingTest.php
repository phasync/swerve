<?php

/*
 * When a worker stops, the coroutines still running get a Swerve\WorkerStoppingException (a
 * phasync ShutdownException) with the reason, and a short window to clean up; then it exits.
 */

foreach ([['SIGTERM to the master', SIGTERM, 'Shutdown'], ['SIGHUP (reload)', SIGHUP, 'Reload']] as [$what, $signal, $reason]) {
    test("$what: a coroutine still running gets WorkerStoppingException with reason $reason", function () use ($signal, $reason) {
        [$process, $addr, $log] = swerve_start([], workers: 1);
        $file                   = temp_path();
        expect(http_get($addr, '/background?file=' . urlencode($file)))->toBe('started');
        swerve_signal($process, $signal);
        $deadline = microtime(true) + 5;
        while (!is_file($file) || '' === file_get_contents($file)) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(20_000);
        }
        expect(file_get_contents($file))->toBe($reason);
        native_stop($process);
    });
}
