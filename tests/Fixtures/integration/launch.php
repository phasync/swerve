<?php

// What Composer's vendor/bin/swerve proxy does: say where the project's autoloader is
$GLOBALS['_composer_autoload_path'] = __DIR__ . '/autoload.php';
require __DIR__ . '/../../../bin/swerve.php';
