<?php

/*
 * A Slim 4 application, to check request and response bodies stream through Slim too.
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/hello', function (Request $request, Response $response) {
    $response->getBody()->write('Hello');

    return $response;
});

// Responds once n bytes of the body have arrived, whatever else the client sends later
$app->post('/first/{n}', function (Request $request, Response $response, array $args) {
    $body = $request->getBody();
    $data = '';
    while (strlen($data) < (int) $args['n']) {
        $data .= $body->read((int) $args['n'] - strlen($data));
    }
    $response->getBody()->write($data);

    return $response;
});

$app->post('/echo-stream', function (Request $request, Response $response) {
    return $response->withBody($request->getBody());
});

$app->post('/count', function (Request $request, Response $response) {
    $body = $request->getBody();
    $hash = hash_init('md5');
    $size = 0;
    while (!$body->eof()) {
        $data  = $body->read(8192);
        $size += strlen($data);
        hash_update($hash, $data);
    }
    $response->getBody()->write($size . ':' . hash_final($hash));

    return $response;
});

return $app;
