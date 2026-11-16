<?php
use Swerve\Swerve;

// A swerve.php that loops, one request at a time: a request's ini_set() is undone after it
ini_set('serialize_precision', '7'); // the application's own, set before its requests: kept
while ($request = Swerve::accept(null, function () {
    echo ini_get('precision'), ' ', ini_get('serialize_precision'), ' ', ini_get('default_charset');
    if (isset($_GET['set'])) {
        ini_set('precision', '5');
        ini_set('serialize_precision', '3');
        ini_set('default_charset', 'ISO-8859-1');
    }
})) {
    phasync::await($request);
}
