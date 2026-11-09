<?php
while (Swerve\Swerve::accept(null, function () {
    header('Content-Type: text/plain');
    if ('/server' === $_SERVER['REQUEST_URI']) {
        echo json_encode(array_intersect_key($_SERVER, array_flip(['GATEWAY_INTERFACE', 'REQUEST_SCHEME', 'DOCUMENT_URI', 'SCRIPT_NAME', 'REDIRECT_STATUS', 'SERVER_SOFTWARE'])));

        return;
    }
    if ('/level' === $_SERVER['REQUEST_URI']) {
        echo 'level ', ob_get_level();

        return;
    }
    echo 'Hello from ', getmypid(), "\n";
})) {
}
