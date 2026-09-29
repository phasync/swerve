<?php
// OpenSwoole WebSocket fan-out: every connection to /news gets what is POSTed to /publish.
// OpenSwoole\Server::SIMPLE_MODE with worker_num N: each worker keeps its own connections; /publish sends the
// message to the other workers over OpenSwoole's worker pipes (sendMessage), and each worker pushes
// it to its own connections. Ports 18400 and 18401.
$server = new OpenSwoole\WebSocket\Server('0.0.0.0', 18400, OpenSwoole\Server::SIMPLE_MODE);
$server->addlistener('0.0.0.0', 18401, OpenSwoole\Constant::SOCK_TCP);
$workers = (int) \getenv('WORKERS');
$server->set([
    'worker_num' => $workers,
    'log_level' => OpenSwoole\Constant::LOG_ERROR,
    'backlog' => 65535,
    'max_connection' => 1000000,
    'websocket_compression' => false,
    'http_compression' => false,
]);
$fds = [];
// WSPACK=1: frame the message once (Server::pack) and send() the same bytes to every connection,
// instead of push(), which frames it per connection
$pack = (bool) \getenv('WSPACK');
$fanout = static function (OpenSwoole\WebSocket\Server $server, string $message) use (&$fds, $pack): void {
    if ($pack) {
        $frame = OpenSwoole\WebSocket\Server::pack($message);
        foreach ($fds as $fd => $_) {
            $server->send($fd, $frame);
        }
    } else {
        foreach ($fds as $fd => $_) {
            $server->push($fd, $message);
        }
    }
};
$server->on('open', static function ($server, OpenSwoole\Http\Request $request) use (&$fds): void {
    $fds[$request->fd] = true;
});
$server->on('close', static function ($server, int $fd) use (&$fds): void {
    unset($fds[$fd]);
});
$server->on('message', static function ($server, $frame): void {});
$server->on('pipeMessage', static function ($server, int $from, $message) use ($fanout): void {
    $fanout($server, $message);
});
$server->on('request', static function (OpenSwoole\Http\Request $request, OpenSwoole\Http\Response $response) use ($server, $fanout, $workers, &$fds): void {
    if ('/publish' === $request->server['request_uri']) {
        $message = $request->rawContent();
        for ($w = 0; $w < $workers; ++$w) {
            if ($w !== $server->worker_id) {
                $server->sendMessage($message, $w);
            }
        }
        $fanout($server, $message);
        $response->end('ok');
    } elseif ('/stats' === $request->server['request_uri']) {
        $response->end(\json_encode(['pid' => \getmypid(), 'sockets' => \count($fds)]));
    } else {
        $response->end('Hello');
    }
});
$server->start();
