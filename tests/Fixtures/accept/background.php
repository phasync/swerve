<?php
// Requests whose code or coroutines outlive their response (#98)
while (Swerve\Swerve::accept(null, function () {
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    $file = $_GET['file'] ?? null;
    if ('/background' === $path) {
        phasync::go(static function () use ($file) {
            phasync::sleep(0.3);
            echo 'dropped'; // the response is complete: this goes nowhere
            file_put_contents($file, connection_aborted() ? 'aborted' : 'not aborted');
        });
        echo 'started';

        return;
    }
    if ('/finish' === $path || '/length' === $path) {
        header('X-Early: yes');
        $ok = null;
        if ('/length' === $path) {
            header('Content-Length: 5');
            echo 'early'; // its declared length: the response is complete
        } else {
            echo 'early';
            $ok = fastcgi_finish_request();
        }
        phasync::sleep(0.3); // the request runs on, its client answered
        echo 'dropped';
        file_put_contents($file, (connection_aborted() ? 'aborted' : 'not aborted') . ' ' . var_export($ok, true) . ' ' . var_export(Swerve\Swerve::finishRequest(), true));

        return;
    }
    echo "hello\n";
})) {
}
