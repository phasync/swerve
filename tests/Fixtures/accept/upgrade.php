<?php
use Swerve\Swerve;
use Swerve\WebSocket;

// Swerve::upgrade(): the application takes over the connection of a request
while (Swerve::accept(null, function () {
    switch ($_SERVER['REQUEST_URI']) {
        case '/raw':
            $client = Swerve::upgrade();
            header('X-Ignored: yes'); // PHP's headers have no place on an upgraded connection
            $client->sendResponseHeaders(200, ['x-raw' => 'yes']);
            $client->write('written to the ClientRequest');
            $client->end();

            return;
        case '/stray':
            $client = Swerve::upgrade();
            try {
                echo 'stray';
                $client->write('echo went through');
            } catch (LogicException) {
                $client->write('echo refused');
            }
            $client->end();

            return;
        case '/late':
            echo 'begun';
            try {
                Swerve::upgrade();
            } catch (LogicException) {
                echo ', upgrade refused';
            }

            return;
        case '/ws':
            WebSocket::from(Swerve::upgrade(), function (WebSocket $ws) {
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
