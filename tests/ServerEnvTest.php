<?php

it('starts each request\'s $_SERVER from what the application set while booting, without the command line\'s entries', function () {
    [, $addr] = swerve_start(workers: 1, fixture: 'server-env.php');
    foreach (['/hello', '/again?x=1'] as $path) {
        expect(json_decode((string) http_get($addr, $path), true))->toBe([
            'APP_ENV' => 'prod', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'argv' => false, 'HTTPS' => null,
        ]);
    }
});
