<?php
// One request after another: the next waits for the tail work of the last
$handler = require __DIR__ . '/tail/handler.php';
while (true) {
    phasync::await(Swerve\Swerve::accept(null, $handler));
}
