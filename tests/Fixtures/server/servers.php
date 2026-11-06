<?php

// Swerve\Server in a process of its own, as a CLI application starts it: one server per handler
// form, their addresses printed as JSON; "close\n" on stdin closes them all.

require __DIR__ . '/../../../vendor/autoload.php';

use phasync\Psr\PsrFactory;
use phasync\Psr\Response;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\ClientRequest;
use Swerve\Server;
use Swerve\ServerSentEvents;
use Swerve\Swerve;

phasync::run(function () {
    $servers = [
        'psr7' => new Server('127.0.0.1:0', fn (ServerRequestInterface $r) => new Response(200, ['Content-Type' => 'text/plain'], (new PsrFactory())->createStream('psr7 ' . $r->getUri()->getPath()))),
        'sapi' => new Server('127.0.0.1:0', function () {
            header('X-Form: sapi');
            echo 'sapi ', $_SERVER['REQUEST_URI'];
        }),
        'client' => new Server('127.0.0.1:0', function (ClientRequest $client) {
            $client->sendResponseHeaders(200, ['content-type' => 'text/plain']);
            $client->write('client ' . $client->getTarget());
            $client->end();
        }),
        'static' => new Server('127.0.0.1:0', null, __DIR__ . '/public'),
        'sse' => new Server('127.0.0.1:0', function () {
            Swerve::upgrade(function (ServerSentEvents $sse) {
                $sse->send('ready');
                Swerve::onShutdown(fn () => $sse->send('closing'));
                Swerve::awaitShutdown();
                $sse->send('draining=' . json_encode(Swerve::draining()));
            });
        }),
    ];
    echo json_encode(array_map(fn (Server $s) => $s->address, $servers)), "\n";
    phasync::readable(STDIN);
    fgets(STDIN);
    foreach ($servers as $server) {
        $server->close();
    }
    echo "closed\n";
});
echo "exited\n";
