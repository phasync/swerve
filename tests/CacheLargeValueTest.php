<?php

/*
 * A large value goes through the master as fast as the pipe takes it, not one 20 ms round of the
 * master's loop per piece of it (issue #21).
 */

test('storing and reading a 1 MB value takes well under a second, and the value is intact', function (int $workers) {
    [$process, $addr] = swerve_start([], $workers, fixture: 'cache-size.php');
    try {
        foreach ([1_000, 100_000, 1_000_000] as $size) {
            [$ok, $setMs] = json_decode((string) probe($addr, "/set?size=$size", 20.0), true);
            // The reading worker may be another one: its local layer has nothing, the master has it
            [$length, $getMs] = json_decode((string) probe($addr, "/get?size=$size", 20.0), true);
            expect([$ok, $length])->toBe([true, $size]);
            if ($size >= 1_000_000) {
                expect($setMs)->toBeLessThan(1000.0)->and($getMs)->toBeLessThan(1000.0);
            }
        }
    } finally {
        native_stop($process);
    }
})->with(['one worker' => 1, 'two workers' => 2]);
