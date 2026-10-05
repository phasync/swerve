<?php

/*
 * Times Swerve::cache() with values of ?size= bytes: /set?size=N stores one and answers the
 * milliseconds it took, /get?size=N reads it back and answers [its length, milliseconds].
 */

use Swerve\ClientRequest;
use Swerve\RequestHandler;
use Swerve\Swerve;

return new RequestHandler(static function (ClientRequest $r) {
    $path = \parse_url($r->getTarget(), \PHP_URL_PATH);
    \parse_str((string) \parse_url($r->getTarget(), \PHP_URL_QUERY), $query);
    $size = (int) ($query['size'] ?? 0);
    $key  = "size$size";
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
    $r->sendResponseHeaders(200, ['Content-Length' => (string) \strlen($body)]);
    $r->write($body);
});
