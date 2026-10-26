<?php
use Swerve\Swerve;

// Concurrent: output after the client is gone stops that request (as under php-fpm), only it
while (Swerve::accept(null, function () {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/stream?')) {
        $marker = sys_get_temp_dir() . '/swerve-abort-' . $_GET['id'];
        try {
            for ($i = 1; $i <= 1000; ++$i) {
                echo str_repeat('x', 65536);
                flush();
                file_put_contents($marker, $i);
                phasync::sleep(0.01);
            }
        } finally {
            file_put_contents("$marker-finally", 'ran');
        }

        return;
    }
    echo getmypid();
})) {
}
