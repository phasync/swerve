<?php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

// One request per run of swerve.php: a 101 goes on in the run, which ends with the connection
phasync::await(Swerve\Swerve::accept(null, static function (ServerRequestInterface $request): ResponseInterface {
    $factory = new phasync\Psr\PsrFactory();
    if (!$request->hasHeader('Upgrade')) {
        $response = $factory->createResponse();
        $response->getBody()->write('Hello');

        return $response;
    }

    $out = new phasync\Psr\UnbufferedStream();
    phasync::go(static function () use ($request, $out): void {
        $in = $request->getBody();
        while ('' !== $chunk = $in->read(8192)) {
            $out->append(strtoupper($chunk));
        }
        $out->end();
    });

    return $factory->createResponse(101)->withHeader('Connection', 'Upgrade')->withHeader('Upgrade', 'shout')->withBody($out);
}));
