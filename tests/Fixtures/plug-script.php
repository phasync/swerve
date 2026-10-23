<?php

// Without ext-phasync: a classic script handler on phasync/hooked's sapi(), see PlugTest

return Swerve\AsyncMode::serve(null, static function (): void {
    if ('/sleep' === parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
        ob_start();
        echo 'before ';
        sleep(1);            // another request runs meanwhile, with its own output buffer
        echo 'after';
        $body = ob_get_clean();
        header('X-Slept: 1');
        echo "$body {$_GET['n']}";

        return;
    }
    header('Content-Type: text/plain');
    header('X-Sapi: ' . PHP_SAPI);
    setcookie('seen', 'yes');
    if ('/form' === $_SERVER['REQUEST_URI']) {
        http_response_code(201);
    }
    echo 'post=', json_encode($_POST), ' input=', file_get_contents('php://input');
    ?> inline<?php
});
