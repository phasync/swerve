<?php
// ReactPHP: react/http's own PSR-7 request into the shared PSR-15 handler; one single-threaded
// process, N of them on one port with SO_REUSEPORT. StreamingRequestMiddleware first: no body
// buffering and no default concurrency limit. Event loop: ext-ev. The handler cannot block, so for
// /wait this adapter waits on a 10 ms timer promise first and the handler's own wait is a no-op.
require __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;

$app = new Handler(static fn () => null);
$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) use ($app) {
    if ('/wait' === $request->getUri()->getPath()) {
        return React\Promise\Timer\sleep(0.01)->then(static fn () => $app->handle($request));
    }

    return $app->handle($request);
});
$http->on('error', static fn (Throwable $e) => \fwrite(\STDERR, $e->getMessage() . "\n"));
$http->listen(new SocketServer('0.0.0.0:' . (\getenv('PORT') ?: 18500), ['tcp' => ['so_reuseport' => true, 'backlog' => 65535]]));
