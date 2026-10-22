<?php

/*
 * Without ext-phasync, application code is loaded through the plug (phasync/babel translating it to
 * call phasync/hooked): blocking functions wait cooperatively, and PHP_SAPI is 'swerve' inside a
 * request and 'cli' outside one, as with the extension's sapi().
 */

beforeEach(function () {
    if (extension_loaded('phasync')) {
        $this->markTestSkipped('ext-phasync is loaded: the plug is only installed without it');
    }
});

it('makes a blocking sleep() cooperative: two requests sleeping a second each take a second together', function () {
    [, $addr] = swerve_start(workers: 1, fixture: 'plug.php');
    $conns = [];
    $start = microtime(true);
    foreach ([1, 2] as $i) {
        $conns[$i] = stream_socket_client("tcp://$addr");
        fwrite($conns[$i], "GET /sleep HTTP/1.1\r\nHost: t\r\nConnection: close\r\n\r\n");
    }
    $bodies = array_map(static fn ($c) => (string) stream_get_contents($c), $conns);
    expect(microtime(true) - $start)->toBeLessThan(1.6)
        ->and($bodies[1])->toContain('boot=cli request=swerve name=swerve')
        ->and($bodies[2])->toContain('boot=cli request=swerve name=swerve');
});
