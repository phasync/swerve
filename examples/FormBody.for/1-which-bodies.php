<?php

use phasync\Psr\StringStream;
use Swerve\Psr\FormBody;

// PHP parses a POST with a form's Content-Type; every other body stays raw
$requests = [
    ['POST', 'application/x-www-form-urlencoded'],
    ['POST', 'multipart/form-data; boundary=x'],
    ['POST', 'application/json'],
    ['PUT', 'application/x-www-form-urlencoded'],
];
foreach ($requests as [$method, $type]) {
    $form = FormBody::for($method, $type, new StringStream(''));
    echo str_pad("$method $type", 45), null === $form ? 'raw' : 'form', "\n";
}
