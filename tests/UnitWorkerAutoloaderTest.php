<?php

/*
 * WorkerAutoloader against a fixture vendor directory (Fixtures/worker-autoload), each case in a
 * fresh PHP process, since loading declares classes: the worker gets swerve's dependency closure
 * (swerve, what it requires, the adapters and what they require), never the application's.
 */

/** Run $code after registering the loader for the fixture (optionally with the root as an adapter), in a fresh process. */
function worker_autoload(string $code, ?array $rootComposer = null): mixed
{
    $fixture = temp_path(dir: true);
    exec('cp -r ' . escapeshellarg(__DIR__ . '/Fixtures/worker-autoload') . '/. ' . escapeshellarg($fixture));
    if (null !== $rootComposer) {
        file_put_contents("$fixture/composer.json", json_encode($rootComposer));
    }
    $script = '<?php require ' . var_export(dirname(__DIR__) . '/src/Util/WorkerAutoloader.php', true) . ';'
        . '$applies = Swerve\Util\WorkerAutoloader::applies(' . var_export("$fixture/vendor", true) . ');'
        . 'Swerve\Util\WorkerAutoloader::register(' . var_export("$fixture/vendor", true) . ', ' . var_export($fixture, true) . ');'
        . $code;
    file_put_contents("$fixture/run.php", $script);

    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$fixture/run.php")), true);
}

test('it applies only to a vendor directory swerve is installed in', function () {
    expect(worker_autoload('echo json_encode($applies);'))->toBeTrue();
    expect(Swerve\Util\WorkerAutoloader::applies(__DIR__ . '/Fixtures'))->toBeFalse();
});

test('the closure of swerve and the adapters loads; the application\'s packages and own classes do not', function () {
    $loads = worker_autoload('echo json_encode(array_map(fn ($c) => class_exists($c) || interface_exists($c), ['
        . '"Swerve\\\\Sub\\\\Thing", "phasync\\\\Loop", "Psr\\\\Log\\\\LoggerInterface", "Acme\\\\Adapter\\\\Entry", "Composer\\\\InstalledVersions",'
        . '"Acme\\\\AppLib\\\\Counter", "Acme\\\\Unrelated\\\\X", "App\\\\Model"]));');
    expect($loads)->toBe([true, true, true, true, true, false, false, false]);
});

test('only the closure\'s files run, and are marked as Composer marks them', function () {
    expect(worker_autoload('echo json_encode([$GLOBALS["ran"], array_keys($GLOBALS["__composer_autoload_files"])]);'))
        ->toBe([['phasync files', 'adapter files'], ['f1', 'f2']]);
});

test('a root package that declares an adapter with its entry is in the closure, with what it requires, but not its vendor directory', function () {
    $loads = worker_autoload(
        'echo json_encode([class_exists("App\\\\Model"), class_exists("Acme\\\\AppLib\\\\Counter"), class_exists("Acme\\\\Unrelated\\\\X"), $GLOBALS["ran"]]);',
        ['name' => 'acme/root', 'require' => ['acme/app-lib' => '*'], 'extra' => ['swerve' => ['adapter' => 'root', 'entry' => 'App\\entry']]],
    );
    expect($loads)->toBe([true, true, false, ['phasync files', 'adapter files', 'app-lib files', 'app files']]);
});

test('a package whose PSR-4 directory is its install path is in the closure; one whose path only starts with it is not', function () {
    expect(worker_autoload('echo json_encode([class_exists("Acme\\\\Flat\\\\Root"), class_exists("Acme\\\\Flatter\\\\Root")]);'))
        ->toBe([true, false]);
});

test('an entry that wants the application\'s classes requires its autoloader, as the README says: they load, and the closure\'s files do not run again', function () {
    expect(worker_autoload(
        '$before = class_exists("App\\\\Model"); $GLOBALS["_composer_autoload_path"] = __DIR__ . "/vendor/autoload.php"; $appDir = __DIR__;'
        . 'require_once $GLOBALS["_composer_autoload_path"] ?? "$appDir/vendor/autoload.php";'
        . 'echo json_encode([$before, class_exists("App\\\\Model"), class_exists("Acme\\\\AppLib\\\\Counter"), $GLOBALS["ran"]]);',
    ))->toBe([false, true, true, ['phasync files', 'adapter files', 'app-lib files', 'app files']]);
});
