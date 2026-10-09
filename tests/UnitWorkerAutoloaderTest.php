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
