<?php

// Without ext-phasync: application code runs through the plug, see PlugTest

use phasync\Psr\Response;
use phasync\Psr\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Swerve;

$boot = PHP_SAPI;

while (true) {
    Swerve::accept(null, static function (ServerRequestInterface $request) use ($boot): ResponseInterface {
        if ('/sleep' === $request->getUri()->getPath()) {
            \sleep(1); // native sleep(): the plug makes it a cooperative wait
        }

        return new Response(200, ['content-type' => 'text/plain'], new StringStream("boot=$boot request=" . PHP_SAPI . ' name=' . \php_sapi_name()));
    });
}
