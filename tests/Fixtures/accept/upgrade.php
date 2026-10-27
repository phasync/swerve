<?php
use Swerve\ClientRequest;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\UpgradeException;
use Swerve\WebSocket;

/** What the request saw after upgrade(), for the closure to tell the client (it runs after the request). */
final class Seen
{
    public static string $note = '';
}

// Swerve::upgrade(): the connection goes on to a closure once the request ended
while (Swerve::accept(null, function () {
    switch (strtok($_SERVER['REQUEST_URI'], '?')) {
        case '/ws-state':
            date_default_timezone_set('Asia/Tokyo');
        $_SESSION = ['user' => 7];
            Swerve::upgrade(static function (WebSocket $ws): void {
                foreach ($ws as $message) {
                    $ws->send(json_encode([$_GET, $_SERVER['QUERY_STRING'], $_SESSION, date_default_timezone_get()]));
                }
            });
            $_GET['changed'] = $_SERVER['QUERY_STRING'] = 'after upgrade()';

            return;
        case '/tz':
            echo json_encode([$_GET, date_default_timezone_get()]);

            return;
        case '/raw':
            Swerve::upgrade(static function (ClientRequest $client): void {
                $client->sendResponseHeaders(200, ['x-raw' => 'yes']);
                $client->write('written to the ClientRequest: ' . Seen::$note);
                $client->end();
            });
            header('X-Ignored: yes'); // the request has no connection any more
            try {
                echo 'stray';
                Seen::$note = 'echo went through';
            } catch (LogicException) {
                Seen::$note = connection_aborted() ? 'echo refused, aborted' : 'echo refused';
            }

            return;
        case '/late':
            echo 'begun';
            try {
                Swerve::upgrade(static function (ClientRequest $client): void {
                });
            } catch (LogicException) {
                echo ', upgrade refused';
            }

            return;
        case '/sse':
            Swerve::upgrade(static function (ServerSentEvents $sse): void {
                $sse->send('one');
                $sse->send('two');
            });

            return;
        case '/ws':
            Swerve::upgrade(static function (WebSocket $ws): void {
                foreach ($ws as $message) {
                    $ws->send("echo $message");
                }
            });

            return;
        case '/ws-uncaught':
            Swerve::upgrade(static function (WebSocket $ws): void {
            });
            echo 'not reached';

            return;
        case '/ws-caught':
            try {
                Swerve::upgrade(static function (WebSocket $ws): void {
                });
            } catch (UpgradeException $e) {
                echo 'refused ' . $e->getCode();
            }

            return;
        case '/buffered':
            ob_start();
            echo 'x';
            try {
                Swerve::upgrade(static function (ClientRequest $client): void {
                });
            } catch (LogicException) {
                ob_end_clean();
                echo 'refused, output buffered';
            }

            return;
        default:
            echo 'hello';
    }
})) {
}
