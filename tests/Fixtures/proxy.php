<?php

/*
 * What the application sees of the client: the address, the scheme and the host, as a request
 * through a proxy shows them.
 */

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(static function (ClientRequest $r) {
    $host = $r->getRequestHeaders()['host'][0];
    $body = \json_encode([
        'remote' => \trim(\substr($r->peer(), 0, \strrpos($r->peer(), ':')), '[]'),
        'https'  => 'https' === $r->getScheme() ? 'on' : null,
        'uri'    => $r->getScheme() . '://' . $host . $r->getTarget(),
        'host'   => $host,
    ]);
    $r->sendResponseHeaders(200, ['Content-Type' => 'application/json', 'Content-Length' => (string) \strlen($body)]);
    $r->write($body);
});
