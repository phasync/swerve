<?php
// RoadRunner: its raw HttpWorker API (no PSR-7 objects), with ext-protobuf loaded for the
// request codec. /wait blocks the worker in usleep(): RoadRunner's model is one request per worker.
require __DIR__ . '/../../../rr/vendor/autoload.php';
require __DIR__ . '/../common/page.php';

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Worker;

$worker = new HttpWorker(Worker::create());
while ($request = $worker->waitRequest()) {
    switch (\parse_url($request->uri, \PHP_URL_PATH)) {
        case '/json':
            $worker->respond(200, \json_encode(['hello' => 'world']), ['Content-Type' => ['application/json']]);
            break;
        case '/wait':
            \usleep(10000);
            $worker->respond(200, 'Waited', ['Content-Type' => ['text/plain']]);
            break;
        case '/page':
            $worker->respond(200, render_page(), ['Content-Type' => ['text/html; charset=utf-8']]);
            break;
        default:
            $worker->respond(200, 'Hello', ['Content-Type' => ['text/plain']]);
    }
}
