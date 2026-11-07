<?php
// A PSR-7 handler's response is the ResponseInterface: PHP's own headers it does not name join it
// (setcookie(), a session's cookie); output with echo throws.
use phasync\Psr\PsrFactory;
use Psr\Http\Message\ServerRequestInterface;

$factory = new PsrFactory();
while (Swerve\Swerve::accept(null, function (ServerRequestInterface $request) use ($factory) {
    switch ($request->getUri()->getPath()) {
        case '/hello':
            return $factory->createResponse(200)->withBody($factory->createStream('hello'));
        case '/cookie':
            setcookie('php', 'set');
            header('Content-Type: text/html');   // the response names it: the response's wins
            return $factory->createResponse(202)->withHeader('Content-Type', 'text/plain')->withBody($factory->createStream('body'));
        case '/echo':
            echo 'stray';
            return $factory->createResponse(200);
    }
})) {
}
