<?php

/*
 * A swerve FastCGI worker for the tests: serves the app below on the address given as the
 * first argument, such as tcp://127.0.0.1:9123.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Swerve\FastCGI\FastCGIServer;
use Swerve\Runners\Psr15Runner;
use Swerve\Swerve;

$app = new class implements RequestHandlerInterface {
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
                'headers' => \array_map(static fn (array $v) => \implode(', ', $v), $request->getHeaders()),
            ])),
            default   => new Response(404, ['Content-Type' => 'text/plain'], 'Not found'),
        };
    }
};

$swerve = new Swerve(new NullLogger());
$swerve->add(new FastCGIServer($argv[1], new NullLogger()));
$swerve->run(new Psr15Runner($app));
