<?php
// Symfony on runtime/swoole: Swoole\Http\Server in SWOOLE_BASE (the runtime's default is
// SWOOLE_PROCESS, slower in set 1), worker_num N. The runtime's options must be set before
// autoload_runtime.php runs this file's closure; otherwise the same front controller as index.php.
// No coroutine hooks: the kernel is shared by the worker, so requests must not interleave.

use App\Kernel;

$_SERVER['APP_RUNTIME'] = 'Runtime\Swoole\Runtime';
$_SERVER['APP_RUNTIME_OPTIONS'] = [
    'host' => '0.0.0.0',
    'port' => (int) (\getenv('PORT') ?: 18500),
    'mode' => 'process' === \getenv('SWMODE') ? \SWOOLE_PROCESS : \SWOOLE_BASE,
    'settings' => [
        'worker_num' => (int) \getenv('WORKERS'),
        'log_level' => \SWOOLE_LOG_ERROR,
        'http_compression' => false,
        'backlog' => 65535,
        'max_connection' => 1000000,
    ],
];

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
