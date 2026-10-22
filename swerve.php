<?php

return Swerve\AsyncMode::serve(null, function (Swerve\ClientRequest $request) {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("Hello, World\n");
});
