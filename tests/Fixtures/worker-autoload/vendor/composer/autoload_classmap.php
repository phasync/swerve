<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Composer\\InstalledVersions' => $vendorDir . '/composer/InstalledVersions.php',
    'Psr\\Log\\LoggerInterface' => $vendorDir . '/psr/log/src/LoggerInterface.php',
    'Acme\\AppLib\\Counter' => $vendorDir . '/acme/app-lib/src/Counter.php',
    'App\\Model' => $baseDir . '/src/Model.php',
);
