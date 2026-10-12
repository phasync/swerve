<?php

use Swerve\Swerve;

// Outside swerve's command line, log() is a NullLogger until setLog() is called
echo get_class(Swerve::log()), "\n";
Swerve::log()->error('nobody sees this');

// It is the same object on every call: hand it to libraries that take a PSR-3 logger
var_dump(Swerve::log() === Swerve::log());
