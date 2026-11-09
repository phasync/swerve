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
if ('/handled' === $_SERVER['REQUEST_URI']) { // as CodeIgniter renders its 404 page
    set_exception_handler(static function (Throwable $e): void {
        http_response_code(404);
        echo 'handled: ', $e->getMessage();
    });

    throw new RuntimeException('no such page');
}
match ($_SERVER['REQUEST_URI']) {
    '/close' => header('Connection: close'),
    default  => null,
};
echo f(), ' count=', $count, ' uri=', $_SERVER['REQUEST_URI'], "\n";
