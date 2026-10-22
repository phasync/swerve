<?php

// $_SERVER as a request sees it: see ServerEnvTest

$_SERVER['APP_ENV'] = 'prod'; // as a framework's .env loader does while booting

return Swerve\AsyncMode::serve(null, static function (Swerve\ClientRequest $request): void {
    $body = json_encode([
        'APP_ENV'        => $_SERVER['APP_ENV'] ?? null,
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
        'REQUEST_URI'    => $_SERVER['REQUEST_URI'],
        'argv'           => array_key_exists('argv', $_SERVER),
        'HTTPS'          => $_SERVER['HTTPS'] ?? null,
    ]);
    $request->sendResponseHeaders(200, ['content-type' => 'application/json', 'content-length' => (string) strlen($body)]);
    $request->write($body);
});
