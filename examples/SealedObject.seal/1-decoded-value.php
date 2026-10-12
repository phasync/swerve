<?php

use Swerve\Util\SealedObject;

$value = SealedObject::seal(json_decode('[{"id": 1, "tags": {"a": true}}, "text", 3]'));

echo get_debug_type($value), "\n";                 // a list stays an array
echo get_debug_type($value[0]), "\n";              // its objects are sealed
echo get_debug_type($value[0]->tags), "\n";
echo $value[1], ' ', $value[2], "\n";
