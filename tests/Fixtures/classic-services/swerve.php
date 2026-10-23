<?php

// Classic scripts using Swerve's services, and an async route reading the same cache: see ClassicServicesTest

return Swerve\ClassicMode::serve('public')
    ->withAsync('/worker/get', static function (Swerve\ClientRequest $request): void {
        parse_str((string) parse_url($request->getTarget(), PHP_URL_QUERY), $q);
        $body = json_encode(Swerve\Swerve::cache()->get($q['k']));
        $request->sendResponseHeaders(200, ['content-length' => (string) strlen($body)]);
        $request->write($body);
    });
