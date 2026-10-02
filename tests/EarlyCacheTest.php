<?php

/*
 * Swerve::cache() and Swerve::publish() wait until the worker serves instead of throwing (issue #22).
 */

test('cache(), publish() and ordered channels wait for the worker to serve in a coroutine started while swerve.php loads', function () {
    [$process, $addr] = swerve_start([], 2, fixture: 'early-cache.php');
    try {
        $report = json_decode((string) probe($addr, '/report', 5.0), true);
        expect($report['go cache'])->toBe('ok');
        expect($report['go publish'])->toBe('ok');
        expect($report['go ordered'])->toBe('ok');
        expect($report['service cache'])->toBe('ok');
        // Directly in swerve.php waiting could never end: the worker serves after the file returns
        expect($report['load'])->toBe(LogicException::class);
    } finally {
        native_stop($process);
    }
});
