<?php
// FrankenPHP worker mode: nyholm/psr7-server's ServerRequestCreator::fromGlobals() per request,
// the response emitted with http_response_code(), header() and echo. /wait blocks the thread.
require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
$app = new Handler();
$handler = static function () use ($creator, $app): void {
    $response = $app->handle($creator->fromGlobals());
    \http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $i => $value) {
            \header("$name: $value", 0 === $i);
        }
    }
    echo $response->getBody();
};
while (\frankenphp_handle_request($handler)) {
}
