<?php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

// One request per run of swerve.php: a 101 can't outlive it
phasync::await(Swerve\Swerve::accept(null, static function (ServerRequestInterface $request): ResponseInterface {
    $factory = new phasync\Psr\PsrFactory();
    if (!$request->hasHeader('Upgrade')) {
        $response = $factory->createResponse();
        $response->getBody()->write('Hello');

        return $response;
    }

    return $factory->createResponse(101)->withHeader('Connection', 'Upgrade')->withHeader('Upgrade', 'shout');
}));
