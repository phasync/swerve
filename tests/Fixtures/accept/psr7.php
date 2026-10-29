<?php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

$factory = new phasync\Psr\PsrFactory();
while (Swerve\Swerve::accept(null, function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    if ('/shout' === $request->getUri()->getPath()) {
        // A protocol over Upgrade: what the client sends after the 101, back in capitals
        $out = new phasync\Psr\UnbufferedStream();
        phasync::go(static function () use ($request, $out): void {
            $in = $request->getBody();
            while ('' !== $chunk = $in->read(8192)) {
                $out->append(strtoupper($chunk));
            }
            $out->end();
        });

        return $factory->createResponse(101)->withHeader('Connection', 'Upgrade')->withHeader('Upgrade', 'shout')->withBody($out);
    }
    $response = $factory->createResponse('POST' === $request->getMethod() ? 201 : 200)->withHeader('X-Method', $request->getMethod());
    $response->getBody()->write('psr7 ' . $request->getUri()->getPath() . ' ' . (string) $request->getBody());

    return $response;
})) {
}
