<?php
// WordPress's shape: files, the PHP files a request names, index.php for the rest
phasync::await(Swerve\Swerve::accept(__DIR__ . '/routed', __DIR__ . '/routed/index.php'));
