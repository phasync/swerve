<?php

use phasync\Psr\StringStream;
use Swerve\Psr\FormBody;

// A url-encoded POST body, as a browser's form sends it
$body = new StringStream('name=Ann+Lee&tags[]=php&tags[]=async&user.age=42');
$form = FormBody::for('POST', 'application/x-www-form-urlencoded', $body);

var_export($form->fields());   // what $_POST would hold: a.b becomes a_b, as in PHP
echo "\n";
