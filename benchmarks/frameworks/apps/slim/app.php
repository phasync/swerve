<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * The Slim 4 benchmark application, identical on every server: /json and /usleep?ms=10.
 * $nap is how /usleep waits: usleep() by default (yields under Swoole's hooks and phasync-ext,
 * blocks under RoadRunner and FrankenPHP); swerve without phasync-ext passes phasync::sleep().
 * Production setup: nyholm/psr7 factories, a route cache, the error middleware without details.
 */
function slim_app(?Closure $nap = null): App
{
    $nap ??= static fn (float $seconds) => \usleep((int) ($seconds * 1e6));
    AppFactory::setResponseFactory(new Psr17Factory());
    $app = AppFactory::create();
    $app->getRouteCollector()->setCacheFile(__DIR__ . '/var/routes.cache.php');
    $app->addRoutingMiddleware();
    $app->addErrorMiddleware(false, false, false);

    $app->get('/json', static function (ServerRequestInterface $request, ResponseInterface $response) {
        $response->getBody()->write(\json_encode(['framework' => 'slim', 'ok' => true]));

        return $response->withHeader('Content-Type', 'application/json');
    });
    $app->get('/usleep', static function (ServerRequestInterface $request, ResponseInterface $response) use ($nap) {
        $ms = (int) ($request->getQueryParams()['ms'] ?? 10);
        $nap($ms / 1000);
        $response->getBody()->write(\json_encode(['waited' => $ms]));

        return $response->withHeader('Content-Type', 'application/json');
    });

    return $app;
}
