<?php

// $_SERVER as a request sees it: see ServerEnvTest

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

$_SERVER['APP_ENV'] = 'prod'; // as a framework's .env loader does while booting

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $r): ResponseInterface {
        $server = $r->getServerParams();
        $body   = \json_encode([
            'APP_ENV'        => $server['APP_ENV'] ?? null,
            'REQUEST_METHOD' => $server['REQUEST_METHOD'],
            'REQUEST_URI'    => $server['REQUEST_URI'],
            'argv'           => \array_key_exists('argv', $server),
            'HTTPS'          => $server['HTTPS'] ?? null,
        ]);

        return new Response(200, ['content-type' => 'application/json'], new StringStream($body));
    });
}
