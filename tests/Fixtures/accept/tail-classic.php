<?php
// One request per run, as WordPress serves: the worker is busy until its tail work ends
phasync::await(Swerve\Swerve::accept(null, require __DIR__ . '/tail/handler.php'));
