<?php

/*
 * Findings from running Symfony and Slim fully concurrently on swerve 0.2.0-RC3 (each request
 * its own coroutine, with `while (Swerve::accept('public/', $handler)) {}`): bugs reduced to a
 * minimal reproduction against tests/Fixtures/concurrent-mode/, using the helpers in Pest.php.
 */

it('reading $_SESSION after phasync::sleep() does not crash the worker', function () {
    // #108. After session_start() and a wait, $_SESSION is unset in the request (isset() false), and
    // reading a key written before the wait kills the worker (signal 11). Without session_start(),
    // or without a wait, $_SESSION survives; sapi() alone in a Scope does not reproduce it.
    [, $addr] = native_start(__DIR__ . '/Fixtures/concurrent-mode/session-sleep.php', workers: 1);

    $conn = native_connect($addr);
    fwrite($conn, "GET /?n=5 HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    expect(native_read_response($conn)['body'] ?? null)->toBe("ok 5\n");
});
