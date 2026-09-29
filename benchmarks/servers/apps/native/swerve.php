<?php
// swerve: a PSR-15 handler. /wait sleeps 10 ms without blocking the worker: usleep() with
// phasync-ext (it suspends only this request's coroutine), phasync\sleep() without it.
require __DIR__ . '/../common/page.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use phasync\Psr\Response;

return new class implements RequestHandlerInterface {
    private bool $ext;

    public function __construct()
    {
        $this->ext = \extension_loaded('phasync');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        switch ($request->getUri()->getPath()) {
            case '/json':
                return new Response(200, ['Content-Type' => 'application/json'], \json_encode(['hello' => 'world']));
            case '/wait':
                $this->ext ? \usleep(10000) : \phasync\sleep(0.01);

                return new Response(200, ['Content-Type' => 'text/plain'], 'Waited');
            case '/page':
                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], render_page());
            default:
                return new Response(200, ['Content-Type' => 'text/plain'], 'Hello');
        }
    }
};
