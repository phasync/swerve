<?php
// The application's Composer autoloader is loaded before the request, as swerve installed into the project loads it (#113)
require_once __DIR__ . '/runtime/vendor/autoload.php';
phasync::await(Swerve\Swerve::accept(__DIR__ . '/runtime/public', __DIR__ . '/runtime/public/index.php'));
