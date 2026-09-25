# Swerve

![SWERVE](swerve-logo.png)

> EARLY DEMO RELEASE, BUGS TO BE EXPECTED

## Getting started

1. Create a file named `swerve.php` in your application root. This file must return
   a PSR-15 RequestHandlerInterface. For example:

```php
<?php

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->get('/', function (RequestInterface $request, ResponseInterface $response) {
    $response->getBody()->write('Hello, World');

    return $response;
});

return $app;
```

2. Install `swerve`: `composer require phasync/swerve`

3. Run `./vendor/bin/swerve` to launch the web server.

## Usage

```bash
> ./vendor/bin/swerve --help
Usage: swerve [-mdhvq] [-w,--workers=<processes>] [--fastcgi=<ip:port>] [--http=<ip:port>] [--log=<path>] [swerve.php]

-m,--monitor              Monitor source code and reload automatically
-d                        Run as daemon
-w,--workers=<processes>  Number of worker processes (default: auto)
--fastcgi=<ip:port>       IP and port for FastCGI server
--http=<ip:port>          IP and port to serve HTTP on (default: 127.0.0.1:8080)
--log=<path>              Log errors to file
-h,--help                 Display this help message
-v,--verbose              Increase logging verbosity, repeat for higher verbosity
-q,--quiet                Suppress all output
[swerve.php]              Full path to application php file
```

## Modes

**HTTP (the default).** swerve starts one worker process per CPU core, and every worker
serves HTTP/1.1 itself on the `--http` address. The kernel spreads new connections over
the workers. There is nothing else to install or run.

**FastCGI (`--fastcgi`).** For running swerve behind a web server of your own, such as
nginx or HAProxy, which speaks FastCGI to the workers. swerve supports several requests
multiplexed over one FastCGI connection; `etc/haproxy.cnf` is an example HAProxy
configuration that uses it.
