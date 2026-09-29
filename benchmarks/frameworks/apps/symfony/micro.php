<?php
// Per-request cost of the Symfony app's /json: the kernel alone, runtime/swoole's conversion is
// not included; swerve-symfony's Handler::handle() with a PSR-7 request (nyholm), no HTTP server.
require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\HttpFoundation\Request;
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/.env');

$n = 50000;
$kernel = new App\Kernel('prod', false);
$req = static fn () => Request::create('http://127.0.0.1:18500/json');
for ($i = 0; $i < 2000; $i++) { $r = $req(); $kernel->terminate($r, $kernel->handle($r)); }
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) { $r = $req(); $kernel->terminate($r, $kernel->handle($r)); }
printf("kernel handle+terminate: %.1f us\n", (hrtime(true) - $t) / $n / 1000);

phasync::run(function () use ($n) {
    $h = require __DIR__ . '/swerve.php';
    $psr = new Nyholm\Psr7\ServerRequest('GET', 'http://127.0.0.1:18500/json', ['Host' => '127.0.0.1:18500'], null, '1.1', ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/json']);
    for ($i = 0; $i < 2000; $i++) { $h->handle($psr); phasync::sleep(0); }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $h->handle($psr); if ($i % 64 === 0) phasync::sleep(0); }
    printf("swerve-symfony Handler::handle: %.1f us\n", (hrtime(true) - $t) / $n / 1000);
});
phasync::run(function () use ($n) {
    $h = require __DIR__ . '/swerve.php';
    $psr = new Nyholm\Psr7\ServerRequest('GET', 'http://127.0.0.1:18500/json', ['Host' => '127.0.0.1:18500'], null, '1.1', ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/json']);
    $to = (new ReflectionMethod($h, 'toSymfony'))->getClosure($h);
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $r = $to($psr); }
    printf("toSymfony: %.1f us, server keys %d\n", (hrtime(true) - $t) / $n / 1000, count($r->server->all()));
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) { phasync::go(static fn () => null); if ($i % 64 === 0) phasync::sleep(0); }
    printf("phasync::go: %.1f us\n", (hrtime(true) - $t) / $n / 1000);
    $kernel = new App\Kernel('prod', false);
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) { $r = $to($psr); $kernel->terminate($r, $kernel->handle($r)); }
    printf("toSymfony + kernel: %.1f us\n", (hrtime(true) - $t) / $n / 1000);
});
