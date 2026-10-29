<?php
// Requests at once: the next is served while the tail work of the last runs on
$handler = require __DIR__ . '/tail/handler.php';
while (Swerve\Swerve::accept(null, $handler)) {
}
