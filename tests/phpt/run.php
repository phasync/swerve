#!/usr/bin/env php
<?php

/*
 * A parity runner for php-src's request-level phpts (--GET--, --POST--, --POST_RAW--,
 * --COOKIE--, --CGI--, --HEADERS--, --EXPECTHEADERS--): it serves each one through Swerve 0.2
 * (the FPM shape: one Swerve\Swerve::accept() call per lifecycle, run_next() looping it) and
 * compares the response against the phpt's own --EXPECT--/--EXPECTF--/--EXPECTREGEX-- and
 * --EXPECTHEADERS-- sections (php-cgi, if installed, is tried first as the baseline instead;
 * see README.md).
 *
 * Usage: php run.php <php-src-path> [dir ...]
 *
 * Prints nothing for a test that matches; a differing test gets one block with a compact diff.
 * Exits non-zero if anything differed. See README.md.
 */

declare(strict_types=1);

const TARGET_SECTIONS = ['GET', 'POST', 'POST_RAW', 'COOKIE', 'CGI', 'HEADERS', 'EXPECTHEADERS'];
const UNSUPPORTED_SECTIONS = [
    'PUT', 'GZIP_POST', 'DEFLATE_POST', 'STDIN', 'CAPTURE_STDIO', 'PHPDBG', 'ARGS',
    'FILE_EXTERNAL', 'REDIRECTTEST', 'FILEEOF', 'XLEAK', 'FLAKY', 'WHITESPACE_SENSITIVE',
];
/**
 * run-tests.php applies these for every test, regardless of php.ini (this system's CLI php.ini
 * has display_errors=Off, which would otherwise swallow every Warning/Deprecated a phpt expects
 * in its body). A test's own --INI-- section is layered on top and can override any of these.
 */
const BASE_INI = [
    'output_handler'          => '',
    'open_basedir'             => '',
    'disable_functions'       => '',
    'output_buffering'        => 'Off',
    'error_reporting'         => '32767',
    'display_errors'          => '1',
    'display_startup_errors'  => '1',
    'log_errors'              => '0',
    'html_errors'             => '0',
    'track_errors'            => '0',
    'docref_root'              => '',
    'docref_ext'              => '.html',
    'auto_prepend_file'       => '',
    'auto_append_file'        => '',
    'ignore_repeated_errors'  => '0',
    'precision'               => '14',
    'serialize_precision'     => '-1',
    'short_open_tag'          => '0',
    'date.timezone'           => 'UTC',
];

function main(array $argv): int
{
    \array_shift($argv);
    if ([] === $argv) {
        \fwrite(\STDERR, "Usage: php run.php <php-src-path> [dir ...]\n");

        return 2;
    }
    $phpSrcRoot = \realpath($argv[0]);
    if (false === $phpSrcRoot || !\is_dir($phpSrcRoot)) {
        \fwrite(\STDERR, "php-src path not found: {$argv[0]}\n");

        return 2;
    }
    $dirs = [] !== \array_slice($argv, 1)
        ? \array_slice($argv, 1)
        : ['tests/basic', 'ext/standard', 'ext/session', 'ext/filter', 'ext/zlib', 'ext/mbstring'];

    $swerveRoot = \dirname(__DIR__, 2);
    $extSo      = \getenv('PHASYNC_EXT_SO');
    if (false === $extSo || !\is_file($extSo)) {
        \fwrite(\STDERR, "PHASYNC_EXT_SO is not set to a built ext-phasync .so\n");

        return 2;
    }
    $phpCgi = find_php_cgi();
    if (null === $phpCgi) {
        \fwrite(\STDERR, "note: no php-cgi found; comparing against each phpt's EXPECT*/EXPECTHEADERS sections only\n");
    }

    $tests = discover_tests($phpSrcRoot, $dirs);

    $fallbackBase = \tempnam($phpSrcRoot, 'phptharness-fallback-');
    $fallback     = "$fallbackBase.php";
    \rename($fallbackBase, $fallback);
    \file_put_contents($fallback, "<?php echo 'PHPT-HARNESS-READY';\n");
    $appFileBase = \tempnam(\sys_get_temp_dir(), 'phptharness-app-');
    $appFile     = "$appFileBase.php";
    \rename($appFileBase, $appFile);
    \file_put_contents($appFile, "<?php\nphasync::await(Swerve\\Swerve::accept(" . \var_export($phpSrcRoot, true) . ', ' . \var_export($fallback, true) . "));\n");

    $totals = ['ran' => 0, 'skipped' => 0, 'matched' => 0, 'differ' => 0];

    try {
        $groups = group_tests($tests);
        foreach ($groups as $group) {
            run_group($group, $phpSrcRoot, $swerveRoot, $extSo, $appFile, $phpCgi, $totals);
        }
    } finally {
        @\unlink($fallback);
        @\unlink($appFile);
    }

    \printf(
        "ran=%d skipped=%d matched=%d differ=%d\n",
        $totals['ran'], $totals['skipped'], $totals['matched'], $totals['differ'],
    );

    return $totals['differ'] > 0 ? 1 : 0;
}

/** @return string[] absolute paths of candidate .phpt files, sorted */
function discover_tests(string $root, array $dirs): array
{
    $pattern = '/^--(' . \implode('|', TARGET_SECTIONS) . ')--\s*$/m';
    $found   = [];
    foreach ($dirs as $dir) {
        $base = $root . '/' . \trim($dir, '/');
        if (!\is_dir($base)) {
            \fwrite(\STDERR, "warning: directory not found: $dir\n");
            continue;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || !\str_ends_with($file->getFilename(), '.phpt')) {
                continue;
            }
            $path = $file->getPathname();
            if (\str_contains($path, '/phar/')) {
                continue; // front-controller tests: out of scope
            }
            $contents = \file_get_contents($path);
            if (false !== $contents && \preg_match($pattern, $contents)) {
                $found[] = $path;
            }
        }
    }
    \sort($found);

    return $found;
}

/** Parses a .phpt into its sections, as run-tests.php's TestFile::readFile() does. */
function parse_phpt(string $path): ?array
{
    $lines = \file($path);
    if (false === $lines || !isset($lines[0]) || !\str_starts_with($lines[0], '--TEST--')) {
        return null;
    }
    $sections = ['TEST' => ''];
    $section  = 'TEST';
    for ($i = 1, $n = \count($lines); $i < $n; ++$i) {
        if (\preg_match('/^--([A-Z_]+)--/', $lines[$i], $m)) {
            $section            = $m[1];
            $sections[$section] = $sections[$section] ?? '';
            continue;
        }
        $sections[$section] = ($sections[$section] ?? '') . $lines[$i];
    }

    return $sections;
}

/** @return array<int, array{path: string, sections: array, ini: array, env: array}> */
function group_tests(array $paths): array
{
    $groups = [];
    foreach ($paths as $path) {
        $sections = parse_phpt($path);
        if (null === $sections) {
            report_bork($path, 'malformed phpt (no --TEST--)');
            continue;
        }
        $ini = BASE_INI;
        if (isset($sections['INI'])) {
            settings2array(\preg_split('/\r?\n/', $sections['INI']), $ini);
        }
        $env = [];
        if (isset($sections['ENV'])) {
            foreach (\preg_split('/\r?\n/', $sections['ENV']) as $line) {
                $parts = \explode('=', \trim($line), 2);
                if ('' !== ($parts[0] ?? '') && isset($parts[1])) {
                    $env[$parts[0]] = $parts[1];
                }
            }
        }
        \ksort($ini);
        \ksort($env);
        $key                      = \md5(\serialize($ini) . "\0" . \serialize($env));
        $groups[$key]           ??= ['ini' => $ini, 'env' => $env, 'tests' => []];
        $groups[$key]['tests'][]  = ['path' => $path, 'sections' => $sections];
    }

    return \array_values($groups);
}

/** Mirrors run-tests.php's settings2array(): "name=value" lines, last one wins. */
function settings2array(array $lines, array &$out): void
{
    foreach ($lines as $line) {
        if (!\str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = \explode('=', $line, 2);
        $out[\trim($name)] = \trim($value);
    }
}

function run_group(array $group, string $phpSrcRoot, string $swerveRoot, string $extSo, string $appFile, ?string $phpCgi, array &$totals): void
{
    $iniFlags = [];
    foreach ($group['ini'] as $name => $value) {
        $iniFlags[] = '-d';
        $iniFlags[] = "$name=$value";
    }
    $addr = free_address();
    $log  = \tempnam(\sys_get_temp_dir(), 'phptharness-log-');
    $cmd  = ['setsid', \PHP_BINARY, '-d', "extension=$extSo", ...$iniFlags, "$swerveRoot/bin/swerve.php", "--http=$addr", '--workers=1', "--log=$log", $appFile];
    $process = \proc_open(
        $cmd,
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a']],
        $pipes,
        null,
        $group['env'] + \getenv(),
    );
    if (false === $process) {
        \fwrite(\STDERR, "could not start swerve for a test group\n");
        foreach ($group['tests'] as $test) {
            report_bork($test['path'], 'could not start swerve');
        }

        return;
    }
    $pid   = \proc_get_status($process)['pid'];
    $ready = false;
    $deadline = \microtime(true) + 10;
    while (\microtime(true) < $deadline) {
        if (null !== probe_ready($addr)) {
            $ready = true;
            break;
        }
        if (!\proc_get_status($process)['running']) {
            break;
        }
        \usleep(20000);
    }
    if (!$ready) {
        \fwrite(\STDERR, "swerve did not start serving on $addr:\n" . (string) @\file_get_contents($log) . "\n");
        foreach ($group['tests'] as $test) {
            report_bork($test['path'], 'swerve did not start for this INI/ENV group');
        }
        kill_by_pid($pid, $process);
        @\unlink($log);

        return;
    }

    foreach ($group['tests'] as $test) {
        run_test($test['path'], $test['sections'], $phpSrcRoot, $addr, $group['ini'], $group['env'], $phpCgi, $totals);
    }

    kill_by_pid($pid, $process);
    @\unlink($log);
}

function probe_ready(string $addr): ?string
{
    $conn = @\stream_socket_client("tcp://$addr", $errno, $errstr, 1);
    if (false === $conn) {
        return null;
    }
    \stream_set_timeout($conn, 2);
    \fwrite($conn, "GET /phptharness-probe HTTP/1.1\r\nHost: test\r\nConnection: close\r\n\r\n");
    $resp = @\stream_get_contents($conn, -1, -1);
    \fclose($conn);
    // Any HTTP response (even a 404, since the probe path does not exist) means swerve is up.
    return (null !== $resp && \str_starts_with((string) $resp, 'HTTP/')) ? $resp : null;
}

function kill_by_pid(int $pid, $process): void
{
    @\posix_kill($pid, \SIGTERM);
    $deadline = \microtime(true) + 5;
    while (\microtime(true) < $deadline && \proc_get_status($process)['running']) {
        \usleep(20000);
    }
    if (\proc_get_status($process)['running']) {
        @\posix_kill($pid, \SIGKILL);
        \usleep(100000);
    }
    @\proc_close($process);
}

function free_address(): string
{
    $probe = \stream_socket_server('tcp://127.0.0.1:0');
    $addr  = \stream_socket_get_name($probe, false);
    \fclose($probe);

    return $addr;
}

function run_test(string $path, array $sections, string $phpSrcRoot, string $addr, array $groupIni, array $groupEnv, ?string $phpCgi, array &$totals): void
{
    foreach (UNSUPPORTED_SECTIONS as $unsupported) {
        if (isset($sections[$unsupported])) {
            ++$totals['skipped'];

            return; // out of scope for this runner; see README.md
        }
    }
    if (isset($sections['XFAIL'])) {
        ++$totals['skipped']; // upstream already does not guarantee this test's output

        return;
    }
    if (!isset($sections['FILE'])) {
        report_bork($path, 'no --FILE-- section');
        ++$totals['skipped'];

        return;
    }
    if ((isset($sections['EXPECT']) ? 1 : 0) + (isset($sections['EXPECTF']) ? 1 : 0) + (isset($sections['EXPECTREGEX']) ? 1 : 0) !== 1) {
        report_bork($path, 'no single --EXPECT--/--EXPECTF--/--EXPECTREGEX-- section');
        ++$totals['skipped'];

        return;
    }

    $dir = \dirname($path);

    if (isset($sections['EXTENSIONS'])) {
        foreach (\preg_split('/\s+/', \trim($sections['EXTENSIONS']), -1, \PREG_SPLIT_NO_EMPTY) as $ext) {
            if (!extension_loaded_ci($ext)) {
                ++$totals['skipped'];

                return;
            }
        }
    }

    $iniFlags = [];
    foreach ($groupIni as $name => $value) {
        $iniFlags[] = '-d';
        $iniFlags[] = "$name=$value";
    }

    if (isset($sections['SKIPIF']) && '' !== \trim($sections['SKIPIF'])) {
        $skip = run_skipif($sections['SKIPIF'], $dir, $iniFlags);
        if (null !== $skip) {
            ++$totals['skipped'];

            return;
        }
    }

    ++$totals['ran'];

    $counter  = ++$GLOBALS['phptharness_counter'];
    $basename = \preg_replace('/[^A-Za-z0-9_-]/', '_', \basename($path, '.phpt'));
    $filename = "{$basename}-harness-{$counter}.php";
    $harness  = "$dir/$filename";
    \file_put_contents($harness, $sections['FILE']);

    try {
        $relDir      = \substr($dir, \strlen($phpSrcRoot));
        $requestName = $relDir . '/' . $filename;

        [$method, $target, $headers, $body] = build_request($sections, $requestName, $groupEnv);

        $response = http_request($addr, $method, $target, $headers, $body);

        $query = isset($sections['GET']) ? \trim($sections['GET']) : '';
        $cgi   = null !== $phpCgi
            ? run_via_php_cgi($phpCgi, $harness, $dir, $iniFlags, $method, $query, $headers, $body)
            : null;
    } finally {
        @\unlink($harness);
    }

    if (null === $response) {
        report_differ($path, 'no response from swerve (connection failed or timed out)', '', '', [], []);
        ++$totals['differ'];

        return;
    }

    $actualBody = \trim(\str_replace("\r\n", "\n", $response['body']));
    if (null !== $cgi) {
        // A live php-cgi ran the same request: compare swerve's body to it byte-for-byte,
        // rather than through EXPECTF's wildcards.
        $wanted = $cgi['body'];
        $bodyOk = $actualBody === $wanted;
    } else {
        [$bodyOk, $wanted] = body_matches($sections, $actualBody);
    }

    $headersOk    = true;
    $wantedHeaders = [];
    $gotHeaders    = [];
    if (isset($sections['EXPECTHEADERS'])) {
        [$headersOk, $wantedHeaders, $gotHeaders] = headers_match($sections['EXPECTHEADERS'], $response);
    }

    if ($bodyOk && $headersOk) {
        ++$totals['matched'];

        return;
    }

    ++$totals['differ'];
    report_differ($path, $bodyOk ? '' : 'body differs', $wanted, $actualBody, $wantedHeaders, $gotHeaders);
}

function extension_loaded_ci(string $name): bool
{
    static $loaded = null;
    if (null === $loaded) {
        $loaded = \array_map('strtolower', \get_loaded_extensions());
        $loaded = \array_map(static fn ($n) => 'zend opcache' === $n ? 'opcache' : $n, $loaded);
    }

    return \in_array(\strtolower($name), $loaded, true);
}

/** @return ?string the skip reason, or null to run the test */
function run_skipif(string $code, string $dir, array $iniFlags): ?string
{
    $counter = ++$GLOBALS['phptharness_counter'];
    $file    = "$dir/skipif-harness-{$counter}.php";
    \file_put_contents($file, $code);
    try {
        $cmd = \array_merge([\PHP_BINARY, ...$iniFlags, '-q', $file]);
        $process = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        if (false === $process) {
            return null;
        }
        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($process);
    } finally {
        @\unlink($file);
    }
    $output = \trim((string) $output);
    if (0 === \strncasecmp('skip', $output, 4)) {
        return $output;
    }

    return null;
}

/**
 * Runs the same request through php-cgi, the real baseline, when it is installed. Not exercised
 * on a system without php-cgi; see README.md.
 *
 * @return ?array{headers: array<string, string>, body: string}
 */
function run_via_php_cgi(string $phpCgi, string $scriptFile, string $dir, array $iniFlags, string $method, string $query, array $headers, string $body): ?array
{
    $env = [
        'REDIRECT_STATUS'   => '1',
        'SCRIPT_FILENAME'   => $scriptFile,
        'SCRIPT_NAME'       => '/' . \basename($scriptFile),
        'PATH_TRANSLATED'   => $scriptFile,
        'QUERY_STRING'      => $query,
        'REQUEST_METHOD'    => $method,
        'CONTENT_TYPE'      => $headers['Content-Type'] ?? '',
        'CONTENT_LENGTH'    => (string) \strlen($body),
        'HTTP_COOKIE'       => $headers['Cookie'] ?? '',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'SERVER_PROTOCOL'   => 'HTTP/1.1',
        'REQUEST_URI'       => '/' . \basename($scriptFile) . ('' !== $query ? "?$query" : ''),
        'SERVER_SOFTWARE'   => 'phptharness',
        'SERVER_NAME'       => 'localhost',
        'REMOTE_ADDR'       => '127.0.0.1',
        'SERVER_PORT'       => '80',
    ];
    foreach ($headers as $name => $value) {
        if (\in_array(\strtolower($name), ['content-type', 'content-length', 'cookie'], true)) {
            continue;
        }
        $env['HTTP_' . \strtoupper(\str_replace('-', '_', $name))] = $value;
    }
    $process = \proc_open(
        [$phpCgi, ...$iniFlags, '-d', 'html_errors=0'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        $env + \getenv(),
    );
    if (false === $process) {
        return null;
    }
    \fwrite($pipes[0], $body);
    \fclose($pipes[0]);
    $out = (string) \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    \proc_close($process);

    if (!\preg_match("/^(.*?)\r?\n\r?\n(.*)/s", $out, $m)) {
        return ['headers' => [], 'body' => \trim(\str_replace("\r\n", "\n", $out))];
    }
    $hdrs = [];
    foreach (\preg_split('/[\n\r]+/', $m[1]) as $line) {
        if (\str_contains($line, ':')) {
            [$name, $value]        = \explode(':', $line, 2);
            $hdrs[\trim($name)] = \trim($value);
        }
    }

    return ['headers' => $hdrs, 'body' => \trim(\str_replace("\r\n", "\n", $m[2]))];
}

/**
 * @return array{0: string, 1: string, 2: array<string, string>, 3: string} [method, target, headers, body]
 */
function build_request(array $sections, string $requestName, array $env = []): array
{
    $headers = [];
    $method  = 'GET';
    $body    = '';
    $query   = isset($sections['GET']) ? \trim($sections['GET']) : '';

    // An --ENV-- HTTP_* entry is how these phpts ask php-cgi (invoked with no real web server in
    // front of it) for a request header: php-cgi takes it as that env var directly, but Swerve
    // only ever sees a header if it is actually on the wire.
    foreach ($env as $name => $value) {
        if (\preg_match('/^HTTP_(.+)$/', $name, $m)) {
            $header           = \str_replace('_', '-', \ucwords(\strtolower($m[1]), '_'));
            $headers[$header] = $value;
        }
    }

    if (isset($sections['COOKIE']) && '' !== \trim($sections['COOKIE'])) {
        $headers['Cookie'] = \trim(\str_replace(["\r", "\n"], '', $sections['COOKIE']));
    }
    if (isset($sections['HEADERS'])) {
        foreach (\preg_split('/\r?\n/', \trim($sections['HEADERS'])) as $line) {
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $headers[\trim($name)] = \trim($value);
            }
        }
    }

    $contentType = $headers['Content-Type'] ?? null;
    if (isset($sections['POST_RAW']) && '' !== \trim($sections['POST_RAW'])) {
        $raw     = \trim($sections['POST_RAW']);
        $lines   = \explode("\n", $raw);
        $built   = '';
        $started = false;
        foreach ($lines as $line) {
            if (null === $contentType && \preg_match('/^Content-Type:(.*)/i', $line, $m)) {
                $contentType = \trim(\str_replace("\r", '', $m[1]));
                continue;
            }
            if ($started) {
                $built .= "\n";
            }
            $started = true;
            $built .= $line;
        }
        $method = 'POST';
        $body   = $built;
        if (null !== $contentType) {
            $headers['Content-Type'] = $contentType;
        }
    } elseif (isset($sections['POST']) && '' !== \trim($sections['POST'])) {
        $body   = \trim($sections['POST']);
        $method = 'POST';
        $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
    }
    if (isset($env['REQUEST_METHOD']) && '' !== $env['REQUEST_METHOD']) {
        // An --ENV-- override (as request_parse_body()'s own tests use, to send a method PHP
        // does not auto-parse the body for): the wire verb, not just the SAPI's belief about it.
        $method = $env['REQUEST_METHOD'];
    }
    if ('' !== $body) {
        $headers['Content-Length'] = (string) \strlen($body);
    }

    $target = $requestName . ('' !== $query ? "?$query" : '');

    return [$method, $target, $headers, $body];
}

function http_request(string $addr, string $method, string $target, array $headers, string $body): ?array
{
    $conn = @\stream_socket_client("tcp://$addr", $errno, $errstr, 5);
    if (false === $conn) {
        return null;
    }
    \stream_set_timeout($conn, 5);
    $head = "$method $target HTTP/1.1\r\nHost: test\r\nConnection: close\r\n";
    foreach ($headers as $name => $value) {
        $head .= "$name: $value\r\n";
    }
    \fwrite($conn, $head . "\r\n" . $body);

    $response = read_response($conn);
    \fclose($conn);

    return $response;
}

/** @return ?array{status: int, headers: array<string, string>, body: string} */
function read_response($conn): ?array
{
    $head = '';
    while (!\str_contains($head, "\r\n\r\n")) {
        $line = \fgets($conn);
        if (false === $line) {
            return null;
        }
        $head .= $line;
    }
    $lines      = \explode("\r\n", \rtrim(\substr($head, 0, -4)));
    $statusLine = \array_shift($lines);
    $parts      = \explode(' ', $statusLine, 3);
    $status     = (int) ($parts[1] ?? 0);
    $reason     = \trim($parts[2] ?? '');
    $headers    = [];
    foreach ($lines as $line) {
        if (\str_contains($line, ':')) {
            [$name, $value] = \explode(':', $line, 2);
            $headers[\trim($name)] = \trim($value);
        }
    }

    $body = '';
    if ($status >= 200 && 204 !== $status && 304 !== $status) {
        $lower = \array_change_key_case($headers, \CASE_LOWER);
        if (isset($lower['content-length'])) {
            $length = (int) $lower['content-length'];
            while (\strlen($body) < $length) {
                $chunk = \fread($conn, $length - \strlen($body));
                if (false === $chunk || ('' === $chunk && \feof($conn))) {
                    break;
                }
                $body .= $chunk;
            }
        } elseif ('chunked' === \strtolower($lower['transfer-encoding'] ?? '')) {
            while (true) {
                $sizeLine = \fgets($conn);
                if (false === $sizeLine) {
                    break;
                }
                $size = \hexdec(\trim($sizeLine));
                if (0 === $size) {
                    \fgets($conn);
                    break;
                }
                $chunk = '';
                while (\strlen($chunk) < $size) {
                    $data = \fread($conn, $size - \strlen($chunk));
                    if (false === $data || ('' === $data && \feof($conn))) {
                        break 2;
                    }
                    $chunk .= $data;
                }
                \fgets($conn); // CRLF after the chunk
                $body .= $chunk;
            }
        } else {
            $body = (string) \stream_get_contents($conn);
        }
    }

    return ['status' => $status, 'reason' => $reason, 'headers' => $headers, 'body' => $body];
}

/** @return array{0: bool, 1: string} [matched, wanted text shown in a diff] */
function body_matches(array $sections, string $actual): array
{
    if (isset($sections['EXPECTF']) || isset($sections['EXPECTREGEX'])) {
        $wanted   = \trim(\str_replace("\r\n", "\n", $sections['EXPECTF'] ?? $sections['EXPECTREGEX']));
        $wantedRe = isset($sections['EXPECTF']) ? expectf_to_regex($wanted) : $wanted;
        $ok       = (bool) @\preg_match('/^' . $wantedRe . '$/s', $actual);

        return [$ok, $wanted];
    }
    $wanted = \trim(\str_replace("\r\n", "\n", $sections['EXPECT'] ?? ''));

    return [$actual === $wanted, $wanted];
}

/** Ported from php-src's run-tests.php (expectf_to_regex()). */
function expectf_to_regex(string $wanted): string
{
    $wantedRe = \str_replace("\r\n", "\n", $wanted);

    $temp        = '';
    $r           = '%r';
    $startOffset = 0;
    $length      = \strlen($wantedRe);
    while ($startOffset < $length) {
        $start = \strpos($wantedRe, $r, $startOffset);
        if (false !== $start) {
            $end = \strpos($wantedRe, $r, $start + 2);
            if (false === $end) {
                $end = $start = $length;
            }
        } else {
            $start = $end = $length;
        }
        $temp .= \preg_quote(\substr($wantedRe, $startOffset, $start - $startOffset), '/');
        if ($end > $start) {
            $temp .= '(' . \substr($wantedRe, $start + 2, $end - $start - 2) . ')';
        }
        $startOffset = $end + 2;
    }
    $wantedRe = $temp;

    return \strtr($wantedRe, [
        '%e' => \preg_quote(\DIRECTORY_SEPARATOR, '/'),
        '%s' => '[^\r\n]+',
        '%S' => '[^\r\n]*',
        '%a' => '.+',
        '%A' => '.*',
        '%w' => '\s*',
        '%i' => '[+-]?\d+',
        '%d' => '\d+',
        '%x' => '[0-9a-fA-F]+',
        '%f' => '[+-]?(?:\d+|(?=\.\d))(?:\.\d+)?(?:[Ee][+-]?\d+)?',
        '%c' => '.',
        '%0' => '\x00',
    ]);
}

/**
 * Mirrors run-tests.php's EXPECTHEADERS comparison: a case-sensitive key lookup, loosely
 * compared. A "Status" line is php-cgi's own convention for the response's status line (an HTTP
 * SAPI like Swerve, as php-fpm, has no such header; its status line carries that directly), so
 * it is matched against the actual status and reason phrase instead of a header.
 *
 * @return array{0: bool, 1: string[], 2: string[]} [matched, wanted lines, actual lines for the wanted keys]
 */
function headers_match(string $expectHeaders, array $response): array
{
    $actualHeaders = $response['headers'];
    $want          = [];
    $wantedLines   = [];
    foreach (\preg_split('/[\n\r]+/', $expectHeaders) as $line) {
        if (\str_contains($line, ':')) {
            [$name, $value]        = \explode(':', $line, 2);
            $want[\trim($name)]    = \trim($value);
            $wantedLines[]         = \trim($name) . ': ' . \trim($value);
        }
    }
    $ok       = true;
    $gotLines = [];
    foreach ($want as $name => $value) {
        if ('status' === \strtolower($name)) {
            $got = \trim($response['status'] . ' ' . $response['reason']);
        } else {
            $got = $actualHeaders[$name] ?? null; // case-sensitive, as run-tests.php compares
        }
        if (null !== $got) {
            $gotLines[] = "$name: $got";
        }
        if (null === $got || $got != $value) {
            $ok = false;
        }
    }

    return [$ok, $wantedLines, $gotLines];
}

function find_php_cgi(): ?string
{
    foreach (['php-cgi8.5', 'php-cgi8.4', 'php-cgi8.3', 'php-cgi'] as $candidate) {
        $path = \trim((string) @\shell_exec('command -v ' . \escapeshellarg($candidate) . ' 2>/dev/null'));
        if ('' !== $path) {
            return $path;
        }
    }

    return null;
}

function report_bork(string $path, string $reason): void
{
    \fwrite(\STDERR, "BORK $path: $reason\n");
}

function report_differ(string $path, string $reason, string $wanted, string $actual, array $wantedHeaders, array $gotHeaders): void
{
    echo "DIFFER $path" . ('' !== $reason ? " ($reason)" : '') . "\n";
    if ('' !== $wanted || '' !== $actual) {
        foreach (compact_diff($wanted, $actual) as $line) {
            echo "  $line\n";
        }
    }
    if ([] !== $wantedHeaders) {
        echo "  --- expected headers ---\n";
        foreach ($wantedHeaders as $line) {
            echo "  - $line\n";
        }
        echo "  --- actual headers ---\n";
        foreach ($gotHeaders as $line) {
            echo "  + $line\n";
        }
    }
    echo "\n";
}

/** @return string[] a short, line-based diff: only the differing lines, capped */
function compact_diff(string $wanted, string $actual): array
{
    $w     = \explode("\n", $wanted);
    $a     = \explode("\n", $actual);
    $out   = [];
    $shown = 0;
    $max   = \max(\count($w), \count($a));
    for ($i = 0; $i < $max; ++$i) {
        $wl = $w[$i] ?? null;
        $al = $a[$i] ?? null;
        if ($wl === $al) {
            continue;
        }
        if ($shown >= 10) {
            $out[] = '... (more lines differ)';
            break;
        }
        if (null !== $wl) {
            $out[] = "- $wl";
        }
        if (null !== $al) {
            $out[] = "+ $al";
        }
        ++$shown;
    }

    return $out;
}

$GLOBALS['phptharness_counter'] = 0;

exit(main($argv));
