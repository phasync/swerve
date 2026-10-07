<?php

// Stands in for a project's vendor/autoload.php: swerve's autoloader, then a package's
// composer.json "files" entry, which Composer loads after swerve's own.
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/integrate-with-swerve.php';
