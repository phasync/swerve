<?php

// tests/ParityTest.php's application under Swerve\Server: prints its address; "close\n" on stdin closes it.

require __DIR__ . '/../../../vendor/autoload.php';

phasync::run(function () {
    $handler = require __DIR__ . '/routes.php';
    $server  = new Swerve\Server('127.0.0.1:0', $handler, __DIR__ . '/public');
    echo $server->address, "\n";
    phasync::readable(STDIN);
    fgets(STDIN);
    $server->close();
    echo "closed\n";
});
