<?php
// Swoole: Swoole\Http\Server, worker_num N, SWMODE=base (SWOOLE_BASE) or process (SWOOLE_PROCESS).
// Every request runs in a coroutine; the hooks make usleep() in /wait yield instead of block.
require __DIR__ . '/../common/page.php';

$port = (int) (\getenv('PORT') ?: 18500);
$mode = 'process' === \getenv('SWMODE') ? \SWOOLE_PROCESS : \SWOOLE_BASE;
$server = new Swoole\Http\Server('0.0.0.0', $port, $mode);
$server->set([
    'worker_num' => (int) \getenv('WORKERS'),
    'enable_coroutine' => true,
    'hook_flags' => \SWOOLE_HOOK_ALL,
    'log_level' => \SWOOLE_LOG_ERROR,
    'http_compression' => false,
    'backlog' => 65535,
    'max_connection' => 1000000,
]);
$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response): void {
    switch ($request->server['request_uri']) {
        case '/json':
            $response->header('Content-Type', 'application/json');
            $response->end(\json_encode(['hello' => 'world']));
            break;
        case '/wait':
            \usleep(10000);
            $response->header('Content-Type', 'text/plain');
            $response->end('Waited');
            break;
        case '/page':
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->end(render_page());
            break;
        default:
            $response->header('Content-Type', 'text/plain');
            $response->end('Hello');
    }
});
$server->start();
