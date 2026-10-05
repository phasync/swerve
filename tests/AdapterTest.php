<?php

/*
 * Which adapter provides the entry point, from the outside: bin/swerve.php started in a temporary
 * application directory with a vendor/composer/installed.json of fixture packages, whose entry
 * functions are in Fixtures/adapter-entry.php (put in reach with auto_prepend_file).
 */

use Swerve\Util\Adapters;

/**
 * An application directory, removed after the test.
 *
 * @param array<string, string> $adapters   adapter name to entry function: a fixture package each
 * @param array|null            $composer   the application's own composer.json
 * @param bool                  $swerveFile whether it has a swerve.php, which answers "Hello" as well
 */
function adapter_app(array $adapters = [], ?array $composer = null, bool $swerveFile = false): string
{
    $dir = temp_path(dir: true);
    if ($adapters) {
        mkdir("$dir/vendor/composer", 0777, true);
        $packages = [['name' => 'acme/plain', 'extra' => ['branch-alias' => []]]];
        foreach ($adapters as $name => $entry) {
            $packages[] = ['name' => "acme/$name-adapter", 'extra' => ['swerve' => ['adapter' => $name, 'entry' => $entry]]];
        }
        file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => $packages, 'dev' => true]));
    }
    if (null !== $composer) {
        file_put_contents("$dir/composer.json", json_encode($composer));
    }
    if ($swerveFile) {
        file_put_contents("$dir/swerve.php", '<?php return SwerveTest\handler();');
    }

    return $dir;
}

/**
 * Start swerve in $dir, without a swerve.php argument unless $file is given.
 *
 * @return array{0: resource, 1: string, 2: string, 3: string, 4: string} the process, its address, the log, stderr, and the file that the entry functions record their calls in
 */
function adapter_spawn(string $dir, array $args = [], ?string $file = null): array
{
    $addr    = free_address();
    $log     = temp_path();
    $err     = temp_path();
    $calls   = temp_path();
    $prelude = __DIR__ . '/Fixtures/adapter-entry.php';
    $process = swerve_spawn(
        ["--http=$addr", '--workers=2', "--log=$log", '--grace=2', '--watchdog=3', ...$args],
        $file,
        ['-d', "auto_prepend_file=$prelude"],
        ['ADAPTER_CALLS' => $calls],
        [2 => ['file', $err, 'w']],
        $dir,
    );

    return [$process, $addr, $log, $err, $calls];
}

/** Start it and wait until it serves; the fixture swerve.php files answer the same as the entry functions. */
function adapter_serve(string $dir, array $args = [], ?string $file = null): array
{
    $started                      = adapter_spawn($dir, $args, $file);
    [$process, $addr, $log, $err] = $started;
    $deadline                     = microtime(true) + 10;
    while ('Hello' !== probe($addr, '/')) {
        if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
            throw new RuntimeException("swerve did not start serving:\n" . file_get_contents($log) . file_get_contents($err));
        }
        usleep(20000);
    }

    return $started;
}

/** Start it and wait for it to stop: its exit code, stderr and log. */
function adapter_fail(string $dir, array $args = [], ?string $file = null): array
{
    [$process, , $log, $err] = adapter_spawn($dir, $args, $file);
    [$code]                  = swerve_wait($process, 5);

    return [$code, file_get_contents($err), file_get_contents($log)];
}

/** What the entry functions recorded: a list of [pid, function, directory]. */
function adapter_calls(string $calls): array
{
    return array_map(static fn ($line) => explode(' ', $line, 3), array_filter(explode("\n", file_get_contents($calls))));
}

test('without an installed adapter, swerve.php is loaded', function () {
    $dir = adapter_app(swerveFile: true);
    [$process, , $log, , $calls] = adapter_serve($dir);
    native_stop($process);

    expect(adapter_calls($calls))->toBe([]);
    expect(file_get_contents($log))->toContain('serving ./swerve.php')->not->toContain('ignored');
});

test('the only installed adapter provides the entry point, called once per worker with the application directory', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry']);
    [$process, , $log, , $calls] = adapter_serve($dir);
    native_stop($process);

    $recorded = adapter_calls($calls);
    expect($recorded)->toHaveCount(2);
    expect(array_unique(array_column($recorded, 0)))->toHaveCount(2);
    expect(array_unique(array_column($recorded, 1)))->toBe(['SwerveTest\entry']);
    expect(array_unique(array_column($recorded, 2)))->toBe([realpath($dir)]);
    expect(file_get_contents($log))->toContain('serving adapter psr15');
});

test('a swerve.php next to an adapter is ignored, with one notice', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry'], swerveFile: true);
    [$process, , $log] = adapter_serve($dir);
    native_stop($process);

    expect(log_count($log, '/swerve\.php is ignored: the adapter psr15 provides the entry point/'))->toBe(1);
});

test('--adapter=swerve loads swerve.php although an adapter is installed', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry'], swerveFile: true);
    [$process, , $log, , $calls] = adapter_serve($dir, ['--adapter=swerve']);
    native_stop($process);

    expect(adapter_calls($calls))->toBe([]);
    expect(file_get_contents($log))->not->toContain('ignored');
});

test('a swerve.php argument works with --adapter=swerve, and is an error with an adapter', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry'], swerveFile: true);

    [$process, , , , $calls] = adapter_serve($dir, ['--adapter=swerve'], "$dir/swerve.php");
    native_stop($process);
    expect(adapter_calls($calls))->toBe([]);

    foreach ([[], ['--adapter=psr15']] as $args) {
        [$code, $err] = adapter_fail($dir, $args, "$dir/swerve.php");
        expect($code)->toBe(2);
        expect($err)->toContain("$dir/swerve.php is given, but the adapter psr15 provides the entry point");
    }
});

test('two adapters and no choice stop swerve before any worker starts, naming both ways to choose', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry', 'laravel' => 'SwerveTest\entry_other']);
    [$code, $err, $log] = adapter_fail($dir);

    expect($code)->toBe(2);
    expect($err)->toContain('Several adapters are installed (psr15, laravel)')->toContain('--adapter=<name>')->toContain('composer.json');
    expect($log)->toBe('');
});

test('the composer.json of the application chooses between adapters, and --adapter overrides it', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry', 'laravel' => 'SwerveTest\entry_other'], ['extra' => ['swerve' => ['adapter' => 'laravel']]]);

    [$process, , , , $calls] = adapter_serve($dir);
    native_stop($process);
    expect(array_unique(array_column(adapter_calls($calls), 1)))->toBe(['SwerveTest\entry_other']);

    [$process, , , , $calls] = adapter_serve($dir, ['--adapter=psr15']);
    native_stop($process);
    expect(array_unique(array_column(adapter_calls($calls), 1)))->toBe(['SwerveTest\entry']);
});

test('an adapter that is not installed stops swerve, whether --adapter or composer.json names it', function (bool $inComposer) {
    $dir          = adapter_app(['psr15' => 'SwerveTest\entry'], $inComposer ? ['extra' => ['swerve' => ['adapter' => 'nope']]] : null, swerveFile: true);
    [$code, $err] = adapter_fail($dir, $inComposer ? [] : ['--adapter=nope']);

    expect($code)->toBe(2);
    expect($err)->toContain('The adapter nope is not installed (installed: psr15)');
})->with(['--adapter' => false, 'composer.json' => true]);

test('an entry that does not return a RequestHandler stops the master with exit code 2', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\entry_bad']);
    [$code, , $log] = adapter_fail($dir);

    expect($code)->toBe(2);
    expect($log)->toContain('The entry SwerveTest\entry_bad of the adapter psr15 returned int; it must return a Swerve\RequestHandler');
});

test('an entry function that does not exist stops the master with exit code 2', function () {
    $dir = adapter_app(['psr15' => 'SwerveTest\missing']);
    [$code, , $log] = adapter_fail($dir);

    expect($code)->toBe(2);
    expect($log)->toContain('Loading the adapter psr15 failed')->toContain('SwerveTest\missing');
});

test('installed.json is read in both of Composer\'s formats, and a package without an entry is refused', function () {
    $dir = temp_path(dir: true);
    mkdir("$dir/vendor/composer", 0777, true);
    $package = ['name' => 'acme/a', 'extra' => ['swerve' => ['adapter' => 'a', 'entry' => 'A\init']]];

    file_put_contents("$dir/vendor/composer/installed.json", json_encode([$package, ['name' => 'acme/b']]));
    expect(Adapters::installed($dir))->toBe(['a' => 'A\init']);

    file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => [$package]]));
    expect(Adapters::installed($dir))->toBe(['a' => 'A\init']);

    file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => [['name' => 'acme/c', 'extra' => ['swerve' => ['adapter' => 'c']]]]]));
    expect(fn () => Adapters::installed($dir))->toThrow(RuntimeException::class, 'acme/c declares the swerve adapter c without an "entry" function');

    file_put_contents("$dir/vendor/composer/installed.json", json_encode(['packages' => [$package, ['name' => 'acme/d'] + $package]]));
    expect(fn () => Adapters::installed($dir))->toThrow(RuntimeException::class, 'acme/d declares the swerve adapter a, which acme/a provides already');
});
