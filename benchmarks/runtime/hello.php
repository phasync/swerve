<?php
// swerve: the smallest handler, answering every request the same way
require __DIR__ . '/../../vendor/autoload.php';

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(function (ClientRequest $request): void {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'content-length' => '13']);
    $request->write('Hello, World!');
    $request->end();
});
