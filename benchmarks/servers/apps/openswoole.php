<?php
// OpenSwoole: OpenSwoole\Http\Server in SIMPLE_MODE (faster than POOL_MODE here; SWMODE=process to
// compare), worker_num N. Each request runs in a coroutine; the hooks make usleep() in /wait yield.
// Requests and responses go through common/swoole-bridge.php to the shared PSR-15 handler.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/common/swoole-bridge.php';

$server = new OpenSwoole\Http\Server('0.0.0.0', (int) (\getenv('PORT') ?: 18500), 'process' === \getenv('SWMODE') ? OpenSwoole\Server::POOL_MODE : OpenSwoole\Server::SIMPLE_MODE);
$server->set([
    'worker_num' => (int) \getenv('WORKERS'),
    'enable_coroutine' => true,
    'hook_flags' => OpenSwoole\Runtime::HOOK_ALL,
    'log_level' => OpenSwoole\Constant::LOG_ERROR,
    'http_compression' => false,
    'backlog' => 65535,
    'max_connection' => 1000000,
]);
$app = new Handler();
$server->on('request', static function (OpenSwoole\Http\Request $request, OpenSwoole\Http\Response $response) use ($app): void {
    swoole_psr_emit($app->handle(swoole_psr_request($request)), $response);
});
$server->start();
