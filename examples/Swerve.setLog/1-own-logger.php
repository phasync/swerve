<?php

use Psr\Log\AbstractLogger;
use Swerve\Swerve;

// Any PSR-3 logger; this one prints each line
Swerve::setLog(new class () extends AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        echo "[$level] $message ", json_encode($context), "\n";
    }
});

Swerve::log()->info('started');
Swerve::log()->warning('disk almost full: {free} MB', ['free' => 512]);
