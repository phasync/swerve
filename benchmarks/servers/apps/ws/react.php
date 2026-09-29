<?php
// ReactPHP WebSocket fan-out: react/http upgrades /news with ratchet/rfc6455's handshake; every
// connection gets what is POSTed to /publish. ReactPHP is single-process: N processes share ports
// 18400 and 18401 with SO_REUSEPORT (INDEX 0..WORKERS-1), and /publish forwards the message to the
// other processes over unix datagram sockets; each process frames it once and writes it to its
// own connections. Event loop: ext-ev.
require __DIR__ . '/../../../react/vendor/autoload.php';

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\ServerRequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;

$index = (int) \getenv('INDEX');
$count = (int) \getenv('WORKERS');
$sock = static fn (int $i): string => "udg:///tmp/react-ws-$i.sock";

$connections = new SplObjectStorage();
$fanout = static function (string $message) use ($connections): void {
    $frame = (new Frame($message, true, Frame::OP_TEXT))->getContents();
    foreach ($connections as $out) {
        $out->write($frame);
    }
};

// Messages from the other processes
@\unlink("/tmp/react-ws-$index.sock");
$inbox = \stream_socket_server($sock($index), $errno, $error, \STREAM_SERVER_BIND);
\stream_set_blocking($inbox, false);
Loop::addReadStream($inbox, static function ($inbox) use ($fanout): void {
    $fanout(\stream_socket_recvfrom($inbox, 65536));
});
$peers = [];
$publish = static function (string $message) use (&$peers, $index, $count, $sock, $fanout): void {
    for ($i = 0; $i < $count; ++$i) {
        if ($i !== $index) {
            $peers[$i] ??= \stream_socket_client($sock($i));
            \fwrite($peers[$i], $message);
        }
    }
    $fanout($message);
};

$negotiator = new ServerNegotiator(new RequestVerifier(), new HttpFactory());
$http = new HttpServer(new StreamingRequestMiddleware(), static function (ServerRequestInterface $request) use ($negotiator, $connections, $publish) {
    switch ($request->getUri()->getPath()) {
        case '/news':
            $handshake = $negotiator->handshake($request);
            if (101 !== $handshake->getStatusCode()) {
                return new Response($handshake->getStatusCode(), $handshake->getHeaders());
            }
            $out = new ThroughStream(); // to the client
            $in = new ThroughStream();  // from the client (ignored: the benchmark client only reads)
            $stream = new CompositeStream($out, $in);
            $connections->attach($out);
            $stream->on('close', static fn () => $connections->detach($out));

            return new Response(101, $handshake->getHeaders(), $stream);
        case '/publish':
            return new Promise(static function ($resolve) use ($request, $publish): void {
                $body = '';
                $request->getBody()->on('data', static function ($data) use (&$body): void { $body .= $data; });
                $request->getBody()->on('end', static function () use (&$body, $resolve, $publish): void {
                    $publish($body);
                    $resolve(new Response(200, [], 'ok'));
                });
            });
        case '/stats':
            return new Response(200, [], \json_encode(['pid' => \getmypid(), 'sockets' => \count($connections), 'memory' => \memory_get_usage(true)]));
        default:
            return new Response(200, [], 'Hello');
    }
});
$http->on('error', static fn (Throwable $e) => \fwrite(\STDERR, $e->getMessage() . "\n"));
foreach ([18400, 18401] as $port) {
    $http->listen(new SocketServer("0.0.0.0:$port", ['tcp' => ['so_reuseport' => true, 'backlog' => 65535]]));
}
