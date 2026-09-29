<?php
// ReactPHP: react/http's own PSR-7 request into the Slim App; one single-threaded process, N of
// them on one port with SO_REUSEPORT; ext-ev loop; StreamingRequestMiddleware first (no body
// buffering, no default concurrency limit). Slim's handlers cannot return a promise, so for
// /usleep this adapter waits on a timer promise first and the route's own wait is a no-op.
require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;

$app = slim_app(static fn () => null);
$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) use ($app) {
    if ('/usleep' === $request->getUri()->getPath()) {
        $ms = (int) ($request->getQueryParams()['ms'] ?? 10);

        return React\Promise\Timer\sleep($ms / 1000)->then(static fn () => $app->handle($request));
    }

    return $app->handle($request);
});
$http->on('error', static fn (Throwable $e) => \fwrite(\STDERR, $e->getMessage() . "\n"));
$http->listen(new SocketServer('0.0.0.0:' . (\getenv('PORT') ?: 18500), ['tcp' => ['so_reuseport' => true, 'backlog' => 65535]]));
