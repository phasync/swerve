<?php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$factory = new phasync\Psr\PsrFactory();
while (Swerve\Swerve::accept(null, function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    header('X-From-Header: yes'); // code inside a PSR-7 application can still use PHP's own functions
    $response = $factory->createResponse();
    $response->getBody()->write(PHP_SAPI . ' ' . json_encode($request->getParsedBody()) . ' ' . json_encode($_POST));

    return $response;
})) {
}
