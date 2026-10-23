<?php

/*
 * Times Swerve::cache() with values of ?size= bytes: /set?size=N stores one and answers the
 * milliseconds it took, /get?size=N reads it back and answers [its length, milliseconds].
 */

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $r): ResponseInterface {
        $path  = $r->getUri()->getPath();
        $query = $r->getQueryParams();
        $size  = (int) ($query['size'] ?? 0);
        $key   = "size$size";
        if ('/set' === $path) {
            $start = \hrtime(true);
            $ok    = Swerve::cache()->set($key, \str_repeat('x', $size));
            $body  = \json_encode([$ok, (\hrtime(true) - $start) / 1e6]);
        } elseif ('/get' === $path) {
            $start = \hrtime(true);
            $value = Swerve::cache()->get($key);
            $body  = \json_encode([\strlen((string) $value), (\hrtime(true) - $start) / 1e6]);
        } else {
            $body = 'ok';
        }

        return new Response(200, [], new StringStream($body));
    });
}
