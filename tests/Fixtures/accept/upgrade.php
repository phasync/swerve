<?php
use Swerve\ClientRequest;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\WebSocket;

/** What the request saw after upgrade(), for the closure to tell the client (it runs after the request). */
final class Seen
{
    public static string $note = '';
}

// Swerve::upgrade(): the connection goes on to a closure once the request ended
while (Swerve::accept(null, function () {
    switch ($_SERVER['REQUEST_URI']) {
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
        default:
            echo 'hello';
    }
})) {
}
