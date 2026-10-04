<?php

/*
 * What the application sees of the client: the address, the scheme and the host, as a request
 * through a proxy shows them.
 */

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $server = $request->getServerParams();

        return new Response(200, ['Content-Type' => 'application/json'], \json_encode([
            'remote' => $server['REMOTE_ADDR'],
            'https'  => $server['HTTPS'] ?? null,
            'uri'    => (string) $request->getUri(),
            'host'   => $request->getHeaderLine('Host'),
        ]));
    }
};
