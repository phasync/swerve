<?php

// tests/ParityTest.php's application under `swerve`: every request as it comes.

$handler = require __DIR__ . '/routes.php';
while (Swerve\Swerve::accept(__DIR__ . '/public', $handler)) {
}
