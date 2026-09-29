<?php
// RoadRunner: the official PSR7Worker with nyholm's factories (ext-protobuf loaded for the codec).
// /usleep blocks the worker: one request per worker is RoadRunner's model.
require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

$factory = new Psr17Factory();
$worker = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
$app = slim_app();
while ($request = $worker->waitRequest()) {
    $worker->respond($app->handle($request));
}
