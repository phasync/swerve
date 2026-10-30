<?php
use Swerve\Swerve;
use Swerve\WebSocket;

// WordPress's shape: one request per lifecycle. A WebSocket runs in the request, which waits
// for it, or moves to the WebSocket workers of a bootstrap file
phasync::await(Swerve::accept(null, function () {
    $boot = __DIR__ . '/ws-boot.php';
    switch (strtok($_SERVER['REQUEST_URI'], '?')) {
        case '/ws-inline':
            $local = new ArrayObject(['inline']);
            Swerve::upgrade(function (WebSocket $ws) use ($local): void {
                foreach ($ws as $message) {
                    $ws->send("{$local[0]} $message from " . getmypid());
                }
            });
            file_put_contents(sys_get_temp_dir() . '/swerve-inline-' . $_GET['id'], connection_aborted() ? 'aborted' : 'connected');

            return;
        case '/ws':
            Swerve::upgrade('ws_echo', $boot);

            return;
        case '/ws-state':
            date_default_timezone_set('Asia/Tokyo');
            $_SESSION = ['user' => 7];
            Swerve::upgrade('ws_state', $boot);

            return;
        case '/ws-busy':
            Swerve::upgrade([WsHandlers::class, 'busy'], $boot);

            return;
        case '/ws-boots':
            Swerve::upgrade('ws_boots', $boot);

            return;
        case '/ws-admin':
            Swerve::upgrade('ws_admin', __DIR__ . '/ws-boot-admin.php');

            return;
        case '/ws-closure':
            $greeting = 'closure';
            Swerve::upgrade(static function (WebSocket $ws) use ($greeting): void {
                foreach ($ws as $message) {
                    $ws->send("$greeting $message from " . getmypid());
                }
            }, $boot);

            return;
        case '/ws-missing':
            Swerve::upgrade('ws_no_such_handler', $boot);

            return;
        case '/ws-broken':
            Swerve::upgrade('ws_echo', __DIR__ . '/ws-boot-broken.php');

            return;
        case '/ws-nofile':
            try {
                Swerve::upgrade('ws_echo', __DIR__ . '/ws-boot-missing.php');
            } catch (LogicException $e) {
                echo 'refused: ', $e->getMessage();
            }

            return;
    }
    // Code of this file, which neither the bootstrap file nor the autoload has: the WebSocket worker does not have it
    if ('/ws-function' === $_SERVER['REQUEST_URI']) {
        if (!function_exists('sl_not_autoloaded')) {
            function sl_not_autoloaded(): string
            {
                return 'here';
            }
        }
        Swerve::upgrade(static function (WebSocket $ws): void {
            foreach ($ws as $message) {
                $ws->send(sl_not_autoloaded());
            }
        }, __DIR__ . '/ws-boot.php');

        return;
    }
    if ('/ws-class' === $_SERVER['REQUEST_URI']) {
        if (!class_exists('SlNotAutoloaded', false)) {
            final class SlNotAutoloaded
            {
                public string $name = 'local';
            }
        }
        $local = new SlNotAutoloaded();
        Swerve::upgrade(static function (WebSocket $ws) use ($local): void {
            $ws->send($local->name);
        }, __DIR__ . '/ws-boot.php');

        return;
    }
    if ('/ws-resource' === $_SERVER['REQUEST_URI']) {
        $file = fopen(__FILE__, 'r');
        try {
            Swerve::upgrade(static function (WebSocket $ws) use ($file): void {
            }, __DIR__ . '/ws-boot.php');
        } catch (LogicException $e) {
            echo 'refused: ', $e->getMessage();
        }

        return;
    }
    echo getmypid();
}));
