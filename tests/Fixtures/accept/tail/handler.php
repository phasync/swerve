<?php

/*
 * Requests with tail work, for the three ways a swerve.php serves (tail-*.php): /length completes
 * its response at its Content-Length, /finish at fastcgi_finish_request(), and both run on 0.3 s
 * after it; /chunked has neither, so its response ends with its script, and a coroutine it started
 * runs on 0.3 s. Each writes [its ?tag, connection_aborted()] to ?file= at its end. /pool says how
 * many request coroutines this lifecycle started.
 */

return static function (): void {
    $path = \strtok($_SERVER['REQUEST_URI'], '?');
    $file = $_GET['file'] ?? null;
    switch ($path) {
        case '/length':
        case '/finish':
            if ('/length' === $path) {
                \header('Content-Length: 4');
                echo 'done';
            } else {
                echo 'done';
                \fastcgi_finish_request();
            }
            phasync::sleep(0.3);
            echo 'dropped';
            \file_put_contents($file, \json_encode([$_GET['tag'], \connection_aborted()]));

            return;
        case '/chunked':
            phasync::go(static function () use ($file): void {
                phasync::sleep(0.3);
                \file_put_contents($file, \json_encode([$_GET['tag'], \connection_aborted()]));
            });
            echo 'done';

            return;
        case '/pool':
            echo Swerve\Http\RequestPool::$started;

            return;
    }
    echo "hello\n";
};
