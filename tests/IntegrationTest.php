<?php

/*
 * A package integrates with swerve from a file in its composer.json "files", which Composer loads
 * after swerve's own, as the master starts: Swerve::ini() and Swerve::onWorkerStart().
 */

test('a package\'s integration file sets php.ini settings and runs code in every worker as it starts', function () {
    $addr    = free_address();
    $process = proc_open(
        ['setsid', PHP_BINARY, __DIR__ . '/Fixtures/integration/launch.php', "--http=$addr", '--workers=2', '--grace=2', __DIR__ . '/Fixtures/app.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    try {
        $deadline = microtime(true) + 10;
        while (null === http_get($addr, '/hello')) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(50_000);
        }
        expect(http_get($addr, '/ini?k=memory_limit'))->toBe('1G');           // over swerve's own -1
        for ($i = 0; $i < 6; ++$i) {
            expect(http_get($addr, '/global?k=fx_worker_started'))->toBe('yes'); // in both workers
        }
    } finally {
        native_stop($process);
    }
});
