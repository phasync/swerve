<?php

/*
 * `-S`/a bare `swerve some-app.php`: the file that runs ($resolved, the fallback router or an
 * existing PHP file Router matched directly) gets the $_SERVER entries mod_php/php-fpm give the
 * script that is about to run (SCRIPT_FILENAME, SCRIPT_NAME, PHP_SELF, PATH_TRANSLATED,
 * DOCUMENT_ROOT), same as any other SAPI -- see Swerve::run(). WordPress depends on these
 * (wp-includes/load.php, swerve#66).
 */

/**
 * A public root with a fallback router.php (what `-S`'s accept($root, $router) answers for a
 * path that isn't a real file) and a direct.php (what Router::APP matches directly, since it
 * exists): both echo the $_SERVER keys the test checks, as JSON.
 *
 * @return array{0: string, 1: string} the public root, and the swerve.php to start with
 */
function script_handler_app(): array
{
    $dir  = temp_path(true);
    $echo = <<<'PHP'
        <?php
        echo json_encode([
            'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            'SCRIPT_NAME'     => $_SERVER['SCRIPT_NAME'] ?? null,
            'PHP_SELF'        => $_SERVER['PHP_SELF'] ?? null,
            'PATH_TRANSLATED' => $_SERVER['PATH_TRANSLATED'] ?? null,
            'DOCUMENT_ROOT'   => $_SERVER['DOCUMENT_ROOT'] ?? null,
            'REQUEST_URI'     => $_SERVER['REQUEST_URI'] ?? null,
        ]);
        PHP;
    mkdir("$dir/public");
    file_put_contents("$dir/public/router.php", $echo);
    file_put_contents("$dir/public/direct.php", $echo);
    $root = realpath("$dir/public");
    file_put_contents("$dir/swerve.php", "<?php\nwhile (true) {\n    Swerve\\Swerve::accept(" . var_export($root, true) . ', ' . var_export("$root/router.php", true) . ");\n}\n");

    return [$root, "$dir/swerve.php"];
}

test("a script handler's \$_SERVER has the keys mod_php/php-fpm give it, for the fallback router and for a file Router matches directly", function () {
    [$root, $fixture] = script_handler_app();
    [$process, $addr] = swerve_start([], 1, fixture: $fixture, wait: false);
    $deadline = microtime(true) + 10;
    while (null === probe($addr, '/anything')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException('swerve did not start serving');
        }
        usleep(20000);
    }
    try {
        // No real file at this path: the fallback router runs, as itself
        expect(json_decode(probe($addr, '/anything'), true))->toBe([
            'SCRIPT_FILENAME' => "$root/router.php",
            'SCRIPT_NAME'     => '/router.php',
            'PHP_SELF'        => '/router.php',
            'PATH_TRANSLATED' => "$root/router.php",
            'DOCUMENT_ROOT'   => $root,
            'REQUEST_URI'     => '/anything',
        ]);
        // direct.php exists under the public root: Router matches it directly, not the router
        expect(json_decode(probe($addr, '/direct.php'), true))->toBe([
            'SCRIPT_FILENAME' => "$root/direct.php",
            'SCRIPT_NAME'     => '/direct.php',
            'PHP_SELF'        => '/direct.php',
            'PATH_TRANSLATED' => "$root/direct.php",
            'DOCUMENT_ROOT'   => $root,
            'REQUEST_URI'     => '/direct.php',
        ]);
    } finally {
        native_stop($process);
    }
});
