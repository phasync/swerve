<?php
use Swerve\Swerve;

// fastcgi_finish_request() and flush() for a client that left: no exception reaches the script
while (Swerve::accept(null, function () {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/late?')) {
        $marker = sys_get_temp_dir() . '/swerve-gone-finish-' . $_GET['id'];
        phasync::sleep(0.3); // the client leaves meanwhile
        try {
            header('X-Late: 1');
            echo str_repeat('x', 100);
            $how = $_GET['how'];
            'finish' === $how ? fastcgi_finish_request() : flush();
            file_put_contents($marker, 'went on ' . (int) connection_aborted());
        } catch (Throwable $e) {
            file_put_contents($marker, 'threw ' . $e::class . ': ' . $e->getMessage());
        }

        return;
    }
    echo getmypid();
})) {
}
