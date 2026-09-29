<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The benchmark application, the same PSR-15 handler on every server; responses come from
 * nyholm/psr7 everywhere. $wait is how /wait spends its 10 ms: usleep() by default (non-blocking
 * under Swoole's hooks and phasync-ext, blocking under RoadRunner and FrankenPHP).
 */
final class Handler implements RequestHandlerInterface
{
    private readonly Psr17Factory $factory;
    private readonly Closure $wait;

    public function __construct(?Closure $wait = null)
    {
        $this->factory = new Psr17Factory();
        $this->wait = $wait ?? static fn () => \usleep(10000);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        switch ($request->getUri()->getPath()) {
            case '/json':
                return $this->respond('application/json', \json_encode(['hello' => 'world']));
            case '/wait':
                ($this->wait)();

                return $this->respond('text/plain', 'Waited');
            case '/page':
                return $this->respond('text/html; charset=utf-8', render_page());
            default:
                return $this->respond('text/plain', 'Hello');
        }
    }

    private function respond(string $type, string $body): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', $type)
            ->withBody($this->factory->createStream($body));
    }
}
