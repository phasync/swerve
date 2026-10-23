<?php

use Swerve\Swerve;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/hello':
        echo 'hello';
        break;
    case '/set':
        echo json_encode(Swerve::cache()->set($_GET['k'], $_GET['v']));
        break;
    case '/set-object':
        Swerve::cache()->set('when', new DateTimeImmutable('2026-10-23 12:00:00 UTC'));
        echo 'ok';
        break;
    case '/get':
        echo json_encode(Swerve::cache()->get($_GET['k'], 'default'));
        break;
    case '/get-object':
        echo Swerve::cache()->get('when')->format('Y-m-d H:i');
        break;
    case '/bad-key':
        try {
            Swerve::cache()->get('a:b');
        } catch (InvalidArgumentException $e) {
            echo get_class($e);
        }
        break;
    case '/subscribe':
        echo 'subscribed', "\n";
        flush();
        foreach (Swerve::subscribe('news', heartbeat: 5) as $message) {
            echo json_encode($message), "\n";
            break;
        }
        break;
    case '/publish':
        Swerve::publish('news', ['headline' => $_GET['h']]);
        echo 'published';
        break;
    case '/ws':
        $prefix = 'echo';   // captured: travels to the worker as a copy
        \Swerve\WebSocket::setup(static function (\Swerve\WebSocket $ws) use ($prefix) {
            foreach ($ws as $message) {
                $ws->send("$prefix: $message");
            }
        });
        echo 'discarded';    // the client is the worker's now
        Swerve::cache()->set('after-setup', connection_aborted() ? 'aborted' : 'running');
        break;
    case '/sse':
        \Swerve\ServerSentEvents::setup(static function (\Swerve\ServerSentEvents $sse) {
            $sse->send('one');
            $sse->send('two');
        });
        break;
    case '/not-static':
        try {
            // A closure bound to an object: it has a $this, which doesn't exist in a worker
            \Swerve\WebSocket::setup((new class { public function make(): Closure { return function ($ws) {}; } })->make());
        } catch (LogicException $e) {
            echo $e->getMessage();
        }
        break;
    case '/header':
        echo json_encode(isset($_SERVER['HTTP_X_SWERVE_SERVICES']));
        break;
}
