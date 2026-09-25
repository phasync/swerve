<?php

/*
 * The application the tests serve, returned the way a project's swerve.php returns it.
 */

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        \parse_str($request->getUri()->getQuery(), $query);

        return match ($request->getUri()->getPath()) {
            '/hello'  => new Response(200, ['Content-Type' => 'text/plain'], 'Hello'),
            '/echo'   => new Response(200, ['Content-Type' => 'text/plain'], (string) $request->getBody()),
            '/big'    => new Response(200, ['Content-Type' => 'text/plain'], \str_repeat('x', (int) $query['n'])),
            '/sleep'  => (static function () use ($query) {
                phasync::sleep(((int) $query['ms']) / 1000);

                return new Response(200, ['Content-Type' => 'text/plain'], 'slept ' . $query['id']);
            })(),
            '/params' => new Response(200, ['Content-Type' => 'application/json'], \json_encode([
                'method'  => $request->getMethod(),
                'target'  => $request->getRequestTarget(),
                'headers' => \array_change_key_case(\array_map(static fn (array $v) => \implode(', ', $v), $request->getHeaders())),
            ])),
            default   => new Response(404, ['Content-Type' => 'text/plain'], 'Not found'),
        };
    }
};
