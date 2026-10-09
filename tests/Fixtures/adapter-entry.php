<?php

/*
 * Entry functions of fixture adapters (see AdapterTest.php), put in each worker's reach with
 * -d auto_prepend_file. Each records its call, one line "pid directory", in the file that the
 * environment variable ADAPTER_CALLS names: "pid function directory".
 */

namespace SwerveTest;

// As Composer's vendor/bin proxy says where the autoloader is: an application whose vendor
// directory is not vendor/ (Joomla's libraries/vendor), see AdapterTest
if (false !== \getenv('ADAPTER_AUTOLOAD')) {
    $GLOBALS['_composer_autoload_path'] = \getenv('ADAPTER_AUTOLOAD');
}

use Swerve\ClientRequest;
use Swerve\RequestHandler;

function record(string $function, string $dir): void
{
    \file_put_contents(\getenv('ADAPTER_CALLS'), \getmypid() . " $function $dir\n", \FILE_APPEND | \LOCK_EX);
}

function handler(): RequestHandler
{
    return new RequestHandler(static function (ClientRequest $r): void {
        $r->sendResponseHeaders(200, ['Content-Length' => '5']);
        $r->write('Hello');
    });
}

function entry(string $dir): RequestHandler
{
    record(__FUNCTION__, $dir);

    return handler();
}

function entry_other(string $dir): RequestHandler
{
    record(__FUNCTION__, $dir);

    return handler();
}

function entry_bad(string $dir): int
{
    record(__FUNCTION__, $dir);

    return 42;
}

/** An entry that takes swerve's `-t` (docroot) and its file argument, and records them. */
function entry_paths(string $dir, ?string $docroot = null, ?string $file = null): RequestHandler
{
    record(__FUNCTION__, \json_encode([$dir, $docroot, $file]));

    return handler();
}
