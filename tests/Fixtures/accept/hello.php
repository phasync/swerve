<?php
while (Swerve\Swerve::accept(null, function () {
    header('Content-Type: text/plain');
    if ('/level' === $_SERVER['REQUEST_URI']) {
        echo 'level ', ob_get_level();

        return;
    }
    echo 'Hello from ', getmypid(), "\n";
})) {
}
