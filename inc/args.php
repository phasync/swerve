<?php

use Swerve\CLI\Args;
use Swerve\CLI\Argument;
use Swerve\CLI\Flag;
use Swerve\CLI\Option;

return (function () {
    $addr_validator = function ($value) {
        // An IPv6 address in brackets, as in a URL: [::1]:8080
        if (!\preg_match('/^(?:\[([^\]]*)\]|([^:\[\]]*)):([^:]*)$/D', $value, $m)) {
            return 'Invalid format (<ip-address>:<port> or [<ipv6-address>]:<port> required)';
        }
        if (false === \filter_var('' !== $m[1] ? $m[1] : $m[2], \FILTER_VALIDATE_IP, '' !== $m[1] ? \FILTER_FLAG_IPV6 : \FILTER_FLAG_IPV4)) {
            return 'Invalid ip address';
        }
        if (false === \filter_var($m[3], \FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ])) {
            return 'Invalid port number. Must be between 1 and 65535.';
        }

        return null;
    };

    $args = new Args();
    $seconds = fn ($value) => \is_numeric($value) && $value >= 0 ? null : 'A number of seconds (0 or more) required';

    $args->add('monitor', new Flag(
        'm', 'monitor', "Watch the application's PHP files and do a rolling reload on change"
    ));
    $args->add('workers', new Option(
        'w', 'workers', 'Number of worker processes',
        default: 'auto',
        validator: function ($value) {
            if ($value === 'auto') {
                return null;
            }
            if (false === \filter_var($value, \FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 256,
                ],
            ])) {
                return 'Integer between 1 and 256 required';
            }

            return null;
        },
        placeholder: 'processes'
    ));
    $args->add('fastcgi', new Option(
        '', 'fastcgi', 'IP and port for FastCGI server; [::1]:9000 for IPv6',
        placeholder: 'ip:port',
        validator: $addr_validator,
        multiple: true
    ));
    $args->add('http', new Option(
        '', 'http', 'IP and port to serve HTTP on; [::1]:8080 for IPv6',
        default: '127.0.0.1:8080',
        placeholder: 'ip:port',
        validator: $addr_validator,
        multiple: true
    ));
    $args->add('bufferResponses', new Flag(
        '', 'buffer-responses', 'HTTP mode: read each response body whole (up to 8 MiB) and send it in one write with a Content-Length'
    ));
    $args->add('maxBody', new Option(
        '', 'max-body', 'HTTP mode: the largest request body in bytes (413), 0 for no limit',
        default: (string) Swerve\Http\NativeHttpConnection::MAX_BODY,
        placeholder: 'bytes',
        validator: fn ($value) => \ctype_digit((string) $value) ? null : 'A number of bytes required',
    ));
    $args->add('grace', new Option(
        '', 'grace', 'Seconds workers get to finish requests on shutdown, reload and recycle before SIGKILL',
        default: '30',
        placeholder: 'seconds',
        validator: $seconds,
    ));
    $args->add('watchdog', new Option(
        '', 'watchdog', 'A worker whose event loop is silent this long is killed and replaced (a request doing more CPU work than this without yielding counts as stuck); at least 1, or 0 = off',
        default: '30',
        placeholder: 'seconds',
        // Heartbeats come every 0.25 s, and the master reads them as often
        validator: fn ($value) => \is_numeric($value) && (0 == $value || $value >= 1) ? null : 'Seconds: 1 or more, or 0 for off',
    ));
    $args->add('maxMemory', new Option(
        '', 'max-memory', 'Recycle a worker above this memory (after gc): bytes, K, M or G, or a % of memory_limit (off when memory_limit is -1); 0 = off',
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
    $args->add('log', new Option(
        '', 'log', 'Append all log lines, and PHP errors, to this file instead of the terminal',
        placeholder: 'path',
        validator: function ($value) {
            $dir = \dirname($value);
            if (!\is_dir($dir)) {
                return "Directory $dir not found";
            }

            return null;
        },
    ));
    $args->add('help', new Flag(
        short: 'h',
        long: 'help',
        description: 'Display this help message'
    ));
    $args->add('verbosity', new Flag(
        'v', 'verbose', 'Log more: -v also info, -vv also debug (by default notices and up)',
        multiple: true
    ));
    $args->add('quiet', new Flag(
        'q', 'quiet', 'Suppress all terminal output (--log still logs to its file)',
    ));
    $args->add('swervefile', new Argument('swerve.php', 'Full path to application php file', './swerve.php'));

    return $args;
})();
