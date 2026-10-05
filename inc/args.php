<?php

use Swerve\CLI\Address;
use Swerve\CLI\Args;
use Swerve\CLI\Argument;
use Swerve\CLI\Flag;
use Swerve\CLI\Option;

return (function () {
    $addr_validator = static function ($value) {
        try {
            Address::normalize($value);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    };

    $args    = new Args();
    $seconds = fn ($value) => \is_numeric($value) && $value >= 0 ? null : 'A number of seconds (0 or more) required';

    $args->section('Application');
    $args->add('swervefile', new Argument('swerve.php', 'A PHP file returning a Swerve\\RequestHandler, which runs once per request with a ClientRequest', './swerve.php'));

    $args->section('Serving');
    $args->add('http', new Option(
        '', 'http', 'Serve HTTP here: 8080 (this machine only), :8080 (every interface), host:port, [ipv6]:port or unix:/path; repeat for several',
        default: '127.0.0.1:8080',
        placeholder: 'address',
        validator: $addr_validator,
        multiple: true
    ));
    $args->add('public', new Option(
        '', 'public', 'HTTP: serve the files in this directory (CSS, JavaScript, images), and pass the rest to the application',
        placeholder: 'dir',
        validator: fn ($value) => \is_dir($value) ? null : "$value is not a directory",
    ));
    $args->add('trustedProxy', new Option(
        '', 'trusted-proxy', 'HTTP: believe the X-Forwarded-For, -Proto and -Host of a proxy at this IP address, range (10.0.0.0/8) or unix (unix: sockets); repeat for several',
        placeholder: 'ip|range|unix',
        validator: static function ($value) {
            try {
                new Swerve\Http\TrustedProxies([$value]);
            } catch (\InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        },
        multiple: true
    ));
    $args->add('workers', new Option(
        'w', 'workers', 'Worker processes; auto is one per CPU core',
        default: 'auto',
        validator: function ($value) {
            if ($value === 'auto') {
                return null;
            }
            if (false === \filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 256]])) {
                return 'auto, or 1 to 256, required';
            }

            return null;
        },
        placeholder: 'n'
    ));

    $args->section('Extension');
    $args->add('ext', new Flag(
        '', 'ext', 'Load phasync-ext (bundled with phasync) even if composer.json does not enable it; swerve stops if it cannot'
    ));

    $args->section('Development');
    $args->add('watch', new Flag(
        '', 'watch', "Reload the workers, one at a time, when a PHP file of the application changes"
    ));

    $args->section('Logging (to the terminal, or with --log to a file)');
    $args->add('verbosity', new Flag(
        'v', 'verbose', 'Log more: -v also what swerve does (workers starting, draining), -vv also debug',
        multiple: true
    ));
    $args->add('quiet', new Flag(
        'q', 'quiet', 'Log nothing to the terminal (--log still logs to its file)',
    ));
    $args->add('noAccessLog', new Flag(
        '', 'no-access-log', 'No line per request',
    ));
    $args->add('log', new Option(
        '', 'log', 'Append the log, and PHP errors, to this file instead',
        placeholder: 'path',
        validator: function ($value) {
            $dir = \dirname($value);
            if (!\is_dir($dir)) {
                return "Directory $dir not found";
            }

            return null;
        },
    ));

    $args->section('Limits');
    $args->add('maxBody', new Option(
        '', 'max-body', 'HTTP: the largest request body in bytes (413), 0 for no limit',
        default: (string) Swerve\Http\HttpConnection::MAX_BODY,
        placeholder: 'bytes',
        validator: fn ($value) => \ctype_digit((string) $value) ? null : 'A number of bytes required',
    ));
    $args->add('grace', new Option(
        '', 'grace', 'Seconds workers get to finish their requests on shutdown, reload and recycle before SIGKILL',
        default: '30',
        placeholder: 'seconds',
        validator: $seconds,
    ));
    $args->add('linger', new Option(
        '', 'linger', 'Seconds a recycled worker may keep serving its upgraded connections (101) after its replacement took over; 0 = not at all',
        default: '1800',
        placeholder: 'seconds',
        validator: $seconds,
    ));
    $args->add('watchdog', new Option(
        '', 'watchdog', 'Replace a worker whose event loop is stuck this long (CPU work that never yields counts); at least 1, 0 = off',
        default: '30',
        placeholder: 'seconds',
        // Heartbeats come every 0.25 s, and the master reads them as often
        validator: fn ($value) => \is_numeric($value) && (0 == $value || $value >= 1) ? null : 'Seconds: 1 or more, or 0 for off',
    ));
    $args->add('maxMemory', new Option(
        '', 'max-memory', 'Recycle a worker above this memory after gc: bytes, K, M or G, or a % of memory_limit; 0 = off',
        default: '80%',
        placeholder: 'size|P%',
        // Over 100 %, the limit could never be reached before memory_limit
        validator: function ($value) {
            if (!\preg_match('/^(\d+)([KMG]?)$|^(\d{1,2}|100)%$/i', (string) $value, $m)) {
                return 'A size such as 512M, or a percentage up to 100%, required';
            }
            // ini_parse_quantity() would wrap around, with a warning from every worker
            if ('' !== $m[1] && $m[1] * 1024 ** \stripos(' KMG', $m[2] ?: ' ') >= \PHP_INT_MAX) {
                return '--max-memory is too large';
            }

            return null;
        },
    ));
    $args->add('maxRequests', new Option(
        '', 'max-requests', 'Recycle a worker after about n requests; 0 = off',
        default: '0',
        placeholder: 'n',
        validator: fn ($value) => \ctype_digit((string) $value) ? null : 'A number of requests required',
    ));

    $args->add('cacheSize', new Option(
        '', 'cache-size', 'The most Swerve::cache() holds, shared by the workers in the master: bytes, K, M or G',
        default: '64M',
        placeholder: 'size',
        validator: fn ($value) => \preg_match('/^[1-9]\d*[KMG]?$/i', (string) $value) ? null : 'A size such as 64M required',
    ));

    $args->section('Information');
    $args->add('help', new Flag('h', 'help', 'This help'));
    $args->add('version', new Flag('', 'version', 'The versions of swerve, PHP, phasync and phasync-ext'));

    return $args;
})();
