<?php

use phasync\Psr\StringStream;
use Swerve\Psr\FormBody;

$form = FormBody::for('POST', 'application/x-www-form-urlencoded', new StringStream('a=1&b=2'));

var_export($form->fields());
echo "\n";
echo $form->input()->getContents(), "\n";     // php://input: still readable after parsing
