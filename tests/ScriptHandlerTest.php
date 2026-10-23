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

/*
 * $_SERVER is a plain global the ext shares across every request (unlike $_GET/$_POST/..., which
 * are Scope state): a script that calls exit() -- WordPress's wp_redirect() + exit, for one, and
 * any other normal use, not a bug -- used to find $_SERVER already reset by the time its own
 * registered shutdown functions ran (WordPress's _wp_cron(), hooked to 'shutdown', reads
 * REQUEST_URI there; swerve#70). Swerve::run() now re-registers its own restore first.
 */
test("a script's own shutdown function still sees this request's \$_SERVER after exit()", function () {
    $dir      = temp_path(true);
    $sentinel = "$dir/seen.txt";
    mkdir("$dir/public");
    // A harmless fallback (also what swerve_start()'s own warmup probes /hello through): the
    // exit()+shutdown-function script restarts its worker (swerve#69, filed separately, not
    // this test's concern), so the request this test actually cares about targets exit-test.php
    // directly (an existing file, Router dispatches it to itself) instead of going through it.
    file_put_contents("$dir/public/router.php", "<?php\necho 'Hello';\n");
    $script = "<?php\nregister_shutdown_function(function () {\n"
        . '    file_put_contents(' . var_export($sentinel, true) . ", \$_SERVER['REQUEST_URI'] ?? 'MISSING');\n"
        . "});\necho 'ok';\nexit;\n";
    file_put_contents("$dir/public/exit-test.php", $script);
    $root = realpath("$dir/public");
    file_put_contents("$dir/swerve.php", "<?php\nwhile (true) {\n    Swerve\\Swerve::accept(" . var_export($root, true) . ', ' . var_export("$root/router.php", true) . ");\n}\n");

    [$process, $addr] = swerve_start([], 1, fixture: "$dir/swerve.php");
    try {
        // Not probe(): the worker this lands on may restart right after answering (swerve#69,
        // filed separately) before this read, possibly, like any 'Connection: close' response, in
        // the middle of the client's own read -- immaterial to what this test checks.
        $conn = native_connect($addr);
        fwrite($conn, "GET /exit-test.php?x=1 HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
        $deadline = microtime(true) + 5;
        while (!file_exists($sentinel)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('the shutdown function never ran');
            }
            usleep(20000);
        }
        expect(file_get_contents($sentinel))->toBe('/exit-test.php?x=1');
    } finally {
        native_stop($process);
    }
});

/*
 * require would run a 'path' handler as part of the closure Swerve::run() calls it from: a
 * top-level variable the script sets becomes local to that one call, invisible to a function of
 * its own that reads it with `global` (WordPress does this throughout, e.g. $user_login in
 * wp-login.php). Swerve::run() uses phasync\ext\include_global() instead, which runs the file as
 * the actual entry point, same as require_once'ing the true main script would.
 */
test("a script's top-level \$x is visible to a function of its own via global \$x", function () {
    $dir = temp_path(true);
    mkdir("$dir/public");
    // A harmless fallback (what swerve_start()'s own warmup probes /hello through, and what a
    // second hit to this worker would use too): global-test.php declares a function, which a
    // second require/include_global of it on the same worker would fail to redeclare (swerve#71,
    // filed separately, not this test's concern) -- so only ever request it once.
    file_put_contents("$dir/public/router.php", "<?php\necho 'Hello';\n");
    file_put_contents("$dir/public/global-test.php", <<<'PHP'
        <?php
        $x = 'top-level-value';
        function global_test_read_x() {
            global $x;
            echo $x ?? 'UNDEFINED';
        }
        global_test_read_x();
        PHP);
    $root = realpath("$dir/public");
    file_put_contents("$dir/swerve.php", "<?php\nwhile (true) {\n    Swerve\\Swerve::accept(" . var_export($root, true) . ', ' . var_export("$root/router.php", true) . ");\n}\n");

    [$process, $addr] = swerve_start([], 1, fixture: "$dir/swerve.php");
    try {
        expect(probe($addr, '/global-test.php'))->toBe('top-level-value');
    } finally {
        native_stop($process);
    }
});
