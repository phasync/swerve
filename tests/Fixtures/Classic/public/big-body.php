<?php

$h   = fopen('php://input', 'rb');
$len = 0;
while (!feof($h)) {
    $chunk = fread($h, 1 << 20);
    $len += strlen($chunk);
}
fclose($h);
echo $len;
