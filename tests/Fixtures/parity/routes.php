<?php

/*
 * One application, for tests/ParityTest.php: served by `swerve` (swerve.php) and by Swerve\Server
 * (server.php), which must give it the same documented API. Returns the handler.
 */

use Swerve\ClientRequest;
use Swerve\ServerSentEvents;
use Swerve\Swerve;
use Swerve\WebSocket;

final class Parity
{
    /** Set by /finish's tail work, read by /tail. */
    public static string $tail = 'not yet';
}

Swerve::container()->has('thing') || Swerve::container()->add('thing', static fn () => new ArrayObject(['made']), 1);

return static function (): void {
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    switch ($path) {
        case '/request':
            header('X-Parity: yes');
            http_response_code(201);
            setcookie('c', 'v');
            echo json_encode([
                PHP_SAPI, $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'], $_SERVER['SERVER_PROTOCOL'],
                $_SERVER['HTTP_HOST'] ?? null, isset($_SERVER['REMOTE_ADDR'], $_SERVER['REMOTE_PORT'], $_SERVER['SERVER_PORT']),
                $_GET, $_COOKIE, Scope::getCurrent() !== Scope::getRootScope(),
            ]);

            return;
        case '/post':
            echo json_encode([$_POST, file_get_contents('php://input'), $_SERVER['CONTENT_TYPE'] ?? null]);

            return;
        case '/session':
            session_start();
            $_SESSION['n'] = ($_SESSION['n'] ?? 0) + 1;
            echo $_SESSION['n'];

            return;
        case '/isolation':
            $mine = $_GET['id'];
            phasync::sleep(0.2);
            echo json_encode([$mine, $_GET['id'], $_SERVER['QUERY_STRING']]);

            return;
        case '/stream':
            echo 'a';
            flush();
            phasync::sleep(0.05);
            echo 'b';

            return;
        case '/finish':
            echo 'answered';
            Swerve::finishRequest();
            phasync::sleep(0.1);
            Parity::$tail = 'tail ran, aborted=' . json_encode(connection_aborted());

            return;
        case '/tail':
            echo Parity::$tail;

            return;
        case '/pubsub':
            $subscription = Swerve::subscribe('parity');
            phasync::go(static fn () => Swerve::publish('parity', ['m' => 1]));
            foreach ($subscription as $message) {
                echo json_encode($message);
                break;
            }

            return;
        case '/cache':
            $cache = Swerve::cache();
            $cache->set('parity', ['v' => 1]);
            echo json_encode([$cache->get('parity'), $cache->has('nope')]);

            return;
        case '/container':
            echo json_encode(Swerve::container()->get('thing')->getArrayCopy());

            return;
        case '/state':
            echo json_encode([Swerve::draining(), Swerve::getVersion() !== 'unknown', Swerve::log() instanceof Psr\Log\LoggerInterface]);

            return;
        case '/accept-inside':
            try {
                Swerve::accept(null, static fn () => null);
                echo 'accepted';
            } catch (LogicException $e) {
                echo get_class($e);
            }

            return;
        case '/throws':
            throw new RuntimeException('parity');
        case '/exit':
            echo 'before exit';
            exit();
        case '/sse':
            Swerve::upgrade(static function (ServerSentEvents $sse): void {
                $sse->send('one');
                $sse->send('two', 'named');
            });

            return;
        case '/ws':
            Swerve::upgrade(static function (WebSocket $ws): void {
                Swerve::onShutdown(static fn () => $ws->end(1001, 'bye'));
                foreach ($ws as $message) {
                    $ws->send("echo $message");
                }
            });

            return;
        case '/raw':
            Swerve::upgrade(static function (ClientRequest $client): void {
                $client->sendResponseHeaders(200, ['content-type' => 'text/plain']);
                $client->write('raw ' . $client->getMethod());
                $client->end();
            });

            return;
        case '/wait-shutdown':
            Swerve::upgrade(static function (ServerSentEvents $sse): void {
                $sse->send('waiting');
                $said = 'awaitShutdown=' . json_encode(Swerve::awaitShutdown(30)) . ' draining=' . json_encode(Swerve::draining());
                phasync::shielded(static fn () => $sse->send($said)); // the drain's waits throw
            });

            return;
    }
    http_response_code(404);
    echo 'no route';
};
