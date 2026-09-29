<?php
// swerve: a PSR-15 handler. /wait sleeps 10 ms without blocking the worker: usleep() with
// phasync-ext (it suspends only this request's coroutine), phasync\sleep() without it.
require __DIR__ . '/../common/page.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Http\Message\Response;

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
                return new Response(\json_encode(['hello' => 'world']), ['Content-Type' => 'application/json']);
            case '/wait':
                $this->ext ? \usleep(10000) : \phasync\sleep(0.01);

                return new Response('Waited', ['Content-Type' => 'text/plain']);
            case '/page':
                return new Response(render_page(), ['Content-Type' => 'text/html; charset=utf-8']);
            default:
                return new Response('Hello', ['Content-Type' => 'text/plain']);
        }
    }
};
