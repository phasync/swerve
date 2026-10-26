<?php

/*
 * The @example files of the docblocks (examples/<symbol>/<n>-<slug>.php): the reference runs
 * each one and shows its output, so each must run on its own, the way the docs build runs it
 * (Composer's autoloader prepended), and print the same every time. The application examples
 * (a directory with a swerve.php) are ExamplesTest's.
 */
$files = array_values(array_filter(
    glob(dirname(__DIR__) . '/examples/*/*.php'),
    static fn (string $file): bool => !is_file(dirname($file) . '/swerve.php'),
));

function run_symbol_example(string $file): array
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/vendor/autoload.php', $file],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        dirname(__DIR__),
    );
    $output = stream_get_contents($pipes[1]);

    return [proc_close($process), $output];
}

test('example runs, and prints the same twice', function (string $file) {
    if (str_contains(file_get_contents($file), "serve.php'")) {
        // These use examples/serve.php, which starts a real bin/swerve.php with the 0.1 contract
        // (RequestHandler, --grace/-q, no extension loaded): not ported to 0.2 yet
        test()->markTestSkipped('0.2: examples/serve.php uses the 0.1 RequestHandler contract, not ported yet');
    }
    [$exit, $output] = run_symbol_example($file);

    expect($exit)->toBe(0, $output);
    expect($output)->not->toMatch('/(Warning|Notice|Deprecated|Fatal error|Uncaught):/');
    expect(run_symbol_example($file))->toBe([0, $output]);
})->with(array_combine(array_map(static fn ($f) => basename(dirname($f)) . '/' . basename($f), $files), array_map(static fn ($f) => [$f], $files)));
