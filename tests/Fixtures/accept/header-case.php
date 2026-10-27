<?php
use Swerve\Swerve;

// Header names go out as the script wrote them
while (Swerve::accept(null, function () {
    if ('/hello' === $_SERVER['REQUEST_URI']) {
        return; // the harness's readiness probe
    }
    header('X-Custom-Name: a');
    header('x-custom-name: b', false);
    header('Location: /elsewhere');
})) {
}
