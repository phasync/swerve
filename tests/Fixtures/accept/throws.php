<?php
// A handler that throws: the client gets a 500, and swerve.php's await() gets the exception
try {
    phasync::await(Swerve\Swerve::accept(null, function () {
        if ('/boom' === $_SERVER['REQUEST_URI']) {
            throw new RuntimeException('boom');
        }
        echo 'ok';
    }));
} catch (RuntimeException $e) {
    file_put_contents(sys_get_temp_dir() . '/swerve-throws-' . getmypid(), $e->getMessage());
}
