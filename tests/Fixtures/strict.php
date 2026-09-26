<?php

/*
 * The test application behind an error handler that turns every notice and warning not
 * silenced with @ into an ErrorException, as Laravel's and Symfony's do. It runs in the
 * worker, so it also sees the warnings of swerve's own code.
 */
\set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
    if (!(\error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($message, 0, $no, $file, $line);
});

return require __DIR__ . '/app.php';
