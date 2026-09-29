<?php
// Swoole: Swoole\Http\Server in SWOOLE_BASE, worker_num N, each request in a coroutine; the hooks
// make usleep() in /usleep yield. Requests and responses through set 1's swoole-bridge.php.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/swoole-bridge.php';

$server = new Swoole\Http\Server('0.0.0.0', (int) (\getenv('PORT') ?: 18500), \SWOOLE_BASE);
$server->set([
    'worker_num' => (int) \getenv('WORKERS'),
    'enable_coroutine' => true,
    'hook_flags' => \SWOOLE_HOOK_ALL,
    'log_level' => \SWOOLE_LOG_ERROR,
    'http_compression' => false,
    'backlog' => 65535,
    'max_connection' => 1000000,
]);
$app = slim_app();
$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($app): void {
    swoole_psr_emit($app->handle(swoole_psr_request($request)), $response);
});
$server->start();
