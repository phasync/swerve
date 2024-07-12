<?php

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Swerve\Psr17\Psr17Factory;

$app = AppFactory::create();

//$app->addErrorMiddleware(true, true, true);

$app->get('/', function (RequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write('<a href="/hello/world">Try /hello/world</a>');
    return $response;
});

$app->get('/hello/{name}', function (RequestInterface $request, ResponseInterface $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello, $name");
    return $response;
});

return $app;
