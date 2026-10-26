<?php
// Run as the main script of a fresh request each time: redeclaring f() would be fatal,
// and $count only ever reaches 1.
function f(): string
{
    global $site;

    return $site;
}
$site  = 'site-' . getmypid();
$count = ($count ?? 0) + 1;
match ($_SERVER['REQUEST_URI']) {
    '/close' => header('Connection: close'),
    default  => null,
};
echo f(), ' count=', $count, ' uri=', $_SERVER['REQUEST_URI'], "\n";
