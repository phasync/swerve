<?php
// A front controller of symfony/runtime (Symfony 5.3+, Drupal 11): it returns the app, which the runtime runs
require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static fn (string $greeting): string => "$greeting, at " . \strtok($_SERVER['REQUEST_URI'], '?');
