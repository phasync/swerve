<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(false, true, true);
$app->get('/hello/{name}', function (Request $request, Response $response, array $args) {
    $response->getBody()->write("Hello, {$args['name']}");
    return $response;
});
$app->post('/echo', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode([$request->getParsedBody(), $request->getQueryParams(), getmypid()]));
    return $response->withHeader('Content-Type', 'application/json');
});
return $app;
