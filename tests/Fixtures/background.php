<?php

/*
 * An application that starts a coroutine as it loads, running for the worker's life.
 */

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$ticks = 0;
phasync::go(static function () use (&$ticks) {
    while (true) {
        phasync::sleep(0.05);
        ++$ticks;
    }
});

return new class($ticks) implements RequestHandlerInterface {
    public function __construct(private int &$ticks)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], (string) $this->ticks);
    }
};
