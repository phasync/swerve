<?php

/*
 * Entry functions of fixture adapters (see AdapterTest.php), put in each worker's reach with
 * -d auto_prepend_file. Each records its call, one line "pid directory", in the file that the
 * environment variable ADAPTER_CALLS names: "pid function directory".
 */

namespace SwerveTest;

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
