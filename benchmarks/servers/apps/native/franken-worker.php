<?php
// FrankenPHP worker mode. /wait blocks the worker thread in usleep(): one request per thread.
require __DIR__ . '/../common/page.php';

$handler = static function (): void {
    switch (\strtok($_SERVER['REQUEST_URI'], '?')) {
        case '/json':
            \header('Content-Type: application/json');
            echo \json_encode(['hello' => 'world']);
            break;
        case '/wait':
            \usleep(10000);
            \header('Content-Type: text/plain');
            echo 'Waited';
            break;
        case '/page':
            \header('Content-Type: text/html; charset=utf-8');
            echo render_page();
            break;
        default:
            \header('Content-Type: text/plain');
            echo 'Hello';
    }
};
while (\frankenphp_handle_request($handler)) {
}
