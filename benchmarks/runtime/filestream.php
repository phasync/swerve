<?php
// swerve: streams a file from disk on every request, without holding it in memory
require __DIR__ . '/../../vendor/autoload.php';

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(function (ClientRequest $request): void {
    $file = fopen(getenv('FILE'), 'rb');
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain', 'content-length' => (string) fstat($file)['size']]);
    $request->sendFile($file);
    fclose($file);
    $request->end();
});
