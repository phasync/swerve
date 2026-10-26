<?php

/*
 * Swerve::publish() waits until the worker serves instead of throwing (issue #22).
 * Swerve::cache() needs no such wait any more (it is APCu, not a trip to the master): it works
 * directly in swerve.php too, not only from a coroutine.
 */

test('cache() works directly in swerve.php and from a coroutine; publish() and ordered channels wait for the worker to serve', function () {
    [$process, $addr] = swerve_start([], 2, fixture: 'early-cache.php');
    try {
        $report = json_decode((string) probe($addr, '/report', 5.0), true);
        expect($report['go cache'])->toBe('ok');
        expect($report['go publish'])->toBe('ok');
        expect($report['go ordered'])->toBe('ok');
        expect($report['service cache'])->toBe('ok');
        // Cache::instance() is APCu, available at once: no waiting for the worker to serve
        expect($report['load'])->toBe('ok');
    } finally {
        native_stop($process);
    }
});
