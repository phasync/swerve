<?php

/*
 * Times Swerve::cache() with values of ?size= bytes: /set?size=N stores one and answers the
 * milliseconds it took, /get?size=N reads it back and answers [its length, milliseconds].
 */

use phasync\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $size = (int) ($request->getQueryParams()['size'] ?? 0);
        $key  = "size$size";
        if ('/set' === $path) {
            $start = \hrtime(true);
            $ok    = Swerve::cache()->set($key, \str_repeat('x', $size));

            return new Response(200, [], \json_encode([$ok, (\hrtime(true) - $start) / 1e6]));
        }
        if ('/get' === $path) {
            $start = \hrtime(true);
            $value = Swerve::cache()->get($key);

            return new Response(200, [], \json_encode([\strlen((string) $value), (\hrtime(true) - $start) / 1e6]));
        }

        return new Response(200, [], 'ok');
    }
};
