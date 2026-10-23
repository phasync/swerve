<?php

/*
 * What the application sees of the client: the address, the scheme and the host, as a request
 * through a proxy shows them.
 */

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $r): ResponseInterface {
        $server = $r->getServerParams();
        $host   = $r->getHeaderLine('host');
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $body   = \json_encode([
            'remote' => $remote,
            'https'  => 'on' === ($server['HTTPS'] ?? null) ? 'on' : null,
            'uri'    => $r->getUri()->getScheme() . '://' . $host . $r->getRequestTarget(),
            'host'   => $host,
        ]);

        return new Response(200, ['Content-Type' => 'application/json'], new StringStream($body));
    });
}
