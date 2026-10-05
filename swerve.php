<?php

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("Hello, World\n");
});
