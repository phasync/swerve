<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'Swerve\\' => array($vendorDir . '/phasync/swerve/src'),
    'phasync\\' => array($vendorDir . '/phasync/phasync/src'),
    'Acme\\Adapter\\' => array($vendorDir . '/acme/adapter/src'),
    'Acme\\Unrelated\\' => array($vendorDir . '/acme/unrelated/src'),
    'App\\' => array($baseDir . '/src'),
);
