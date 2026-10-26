<?php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$factory = new phasync\Psr\PsrFactory();
while (Swerve\Swerve::accept(null, function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    $response = $factory->createResponse('POST' === $request->getMethod() ? 201 : 200)->withHeader('X-Method', $request->getMethod());
    $response->getBody()->write('psr7 ' . $request->getUri()->getPath() . ' ' . (string) $request->getBody());

    return $response;
})) {
}
