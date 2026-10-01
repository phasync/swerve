<?php
// swerve: a PSR-15 handler that reads a file from disk on every request and serves it
require __DIR__ . '/../../vendor/autoload.php';

return new class () implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
    {
        return new phasync\Psr\Response(200, ['Content-Type' => 'text/plain'], file_get_contents(getenv('FILE')));
    }
};
