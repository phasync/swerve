<?php
// Swoole: Swoole\Http\Server in SWOOLE_BASE (faster than SWOOLE_PROCESS here; SWMODE=process to
// compare), worker_num N. Each request runs in a coroutine; the hooks make usleep() in /wait yield.
// Requests and responses go through common/swoole-bridge.php to the shared PSR-15 handler.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/common/swoole-bridge.php';

$server = new Swoole\Http\Server('0.0.0.0', (int) (\getenv('PORT') ?: 18500), 'process' === \getenv('SWMODE') ? \SWOOLE_PROCESS : \SWOOLE_BASE);
$server->set([
    'worker_num' => (int) \getenv('WORKERS'),
    'enable_coroutine' => true,
    'hook_flags' => \SWOOLE_HOOK_ALL,
    'log_level' => \SWOOLE_LOG_ERROR,
    'http_compression' => false,
    'backlog' => 65535,
    'max_connection' => 1000000,
]);
$app = new Handler();
$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($app): void {
    swoole_psr_emit($app->handle(swoole_psr_request($request)), $response);
});
$server->start();
