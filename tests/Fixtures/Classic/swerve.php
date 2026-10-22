<?php

/*
 * ClassicServerTest.php's fixture: a Classic application served by ext-phasync's request
 * server. The router is a separate script from public/index.php, so the directory-index and
 * router-fallback behaviours can be tested independently. `/hello` is an async route (the path
 * swerve_start()/native_start() probe for readiness): it never touches the Classic backend, so
 * a test can tell the worker is ready without forking a backend child.
 */

use Swerve\ClassicMode;
use Swerve\ClientRequest;

return ClassicMode::serve('public', 'public/router.php')
    ->withAsync('^/hello$', function (ClientRequest $r): void {
        $r->sendResponseHeaders(200, ['Content-Length' => '5']);
        $r->write('Hello');
    });
