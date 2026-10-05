<?php
// swerve: reads a file from disk on every request and serves it
require __DIR__ . '/../../vendor/autoload.php';

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(function (ClientRequest $request): void {
    $body = file_get_contents(getenv('FILE'));
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'content-length' => (string) strlen($body)]);
    $request->write($body);
    $request->end();
});
