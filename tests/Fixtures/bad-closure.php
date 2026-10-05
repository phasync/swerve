<?php

// A bare closure, not wrapped in a Swerve\RequestHandler
return static function (Swerve\ClientRequest $request): void {
    $request->end();
};
