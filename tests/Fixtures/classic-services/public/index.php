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
    case '/header':
        echo json_encode(isset($_SERVER['HTTP_X_SWERVE_SERVICES']));
        break;
}
