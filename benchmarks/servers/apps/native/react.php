<?php
// ReactPHP: one single-threaded process; run N of them on one port with SO_REUSEPORT.
// StreamingRequestMiddleware first: no request-body buffering and no default concurrency limit
// (the documented way to run react/http for throughput). The event loop is ext-ev's (Loop picks
// it when the extension is loaded). /wait resolves a 10 ms timer promise.
require __DIR__ . '/../../../react/vendor/autoload.php';
require __DIR__ . '/../common/page.php';

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;

$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) {
    switch ($request->getUri()->getPath()) {
        case '/json':
            return new Response(200, ['Content-Type' => 'application/json'], \json_encode(['hello' => 'world']));
        case '/wait':
            return React\Promise\Timer\sleep(0.01)->then(static fn () => new Response(200, ['Content-Type' => 'text/plain'], 'Waited'));
        case '/page':
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], render_page());
        default:
            return new Response(200, ['Content-Type' => 'text/plain'], 'Hello');
    }
});
$http->on('error', static fn (Throwable $e) => \fwrite(\STDERR, $e->getMessage() . "\n"));
$http->listen(new SocketServer('0.0.0.0:' . (\getenv('PORT') ?: 18500), ['tcp' => ['so_reuseport' => true, 'backlog' => 65535]]));
