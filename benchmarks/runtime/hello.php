<?php
// swerve: the smallest PSR-15 handler, answering every request the same way
require __DIR__ . '/../../vendor/autoload.php';

return new class () implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        return new phasync\Psr\Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!');
    }
};
