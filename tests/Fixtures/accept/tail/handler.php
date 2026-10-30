<?php

/*
 * Requests with tail work, for the three ways a swerve.php serves (tail-*.php): /length completes
 * its response at its Content-Length, /finish at fastcgi_finish_request(), and both run on 0.3 s
 * after it; /chunked has neither, so its response ends with its script, and a coroutine it started
 * runs on 0.3 s. Each writes [its ?tag, connection_aborted()] to ?file= at its end. /pool says how
 * many spare coroutines this lifecycle started, /moved how many times a connection went on in one.
 * /coroutine says which coroutine runs it; /busy says so too, at its Content-Length, then works on
 * without suspending: in one C call, or in a loop of ?ms= (which phasync preempts on its 20 ms
 * timer), and /moves sleeps 0.2 s.
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
        case '/coroutine':
            echo \spl_object_id(\Fiber::getCurrent());

            return;
        case '/busy':
        case '/moves':
            $id = (string) \spl_object_id(\Fiber::getCurrent());
            \header('Content-Length: ' . \strlen($id));
            echo $id;
            if ('/moves' === $path) {
                phasync::sleep(0.2);
            } elseif (isset($_GET['ms'])) {
                for ($until = \hrtime(true) + (int) $_GET['ms'] * 1_000_000; \hrtime(true) < $until;) {
                }
            } else {
                \hash('sha256', \str_repeat('x', 20_000_000)); // no loop of PHP's, where it could be preempted
            }

            return;
        case '/pool':
            echo Swerve\Http\RequestPool::$started;

            return;
        case '/moved':
            echo Swerve\Http\HttpConnection::$moves;

            return;
    }
    echo "hello\n";
};
