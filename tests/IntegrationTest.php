<?php

/*
 * A package integrates with swerve from a file in its composer.json "files", which Composer loads
 * after swerve's own, as the master starts: Swerve::ini() and Swerve::onWorkerStart().
 */

test('a package\'s integration file sets php.ini settings and runs code in every worker as it starts', function () {
    // Swerve only ever loads ./swerve.php of its working directory: a fresh directory, with the
    // fixture symlinked as that, so it is found regardless of where this test happens to run from
    $addr = free_address();
    $dir  = temp_path(true);
    symlink(__DIR__ . '/Fixtures/app.php', "$dir/swerve.php");
    $env  = \getenv() + ['SWERVE_TEST_DIR' => $dir];
    $process = proc_open(
        // --ext, as every test run of swerve does (see swerve_spawn())
        ['setsid', PHP_BINARY, __DIR__ . '/Fixtures/integration/launch.php', "--http=$addr", '--workers=2', '--grace=2', '--ext'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $dir,
        $env,
    );
    $GLOBALS['swerve_groups'][] = proc_get_status($process)['pid'];
    try {
        $deadline = microtime(true) + 10;
        while (null === http_get($addr, '/hello')) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(50_000);
        }
        expect(http_get($addr, '/ini?k=memory_limit'))->toBe('1G');           // over swerve's own -1
        // Every worker ran the callback as it started: one marker file per process
        $deadline = microtime(true) + 5;
        while (\count(\glob("$dir/worker-start-*")) < 2) {
            expect(microtime(true))->toBeLessThan($deadline);
            usleep(50_000);
        }
        expect(\count(\glob("$dir/worker-start-*")))->toBeGreaterThanOrEqual(2);
    } finally {
        native_stop($process);
    }
});
