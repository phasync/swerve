<?php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$factory = new phasync\Psr\PsrFactory();
while (Swerve\Swerve::accept('public/', function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    $response = $factory->createResponse(201)->withHeader('X-Method', $request->getMethod());
    $response->getBody()->write('psr7 ' . $request->getUri()->getPath() . ' ' . (string) $request->getBody());

    return $response;
})) {
}
