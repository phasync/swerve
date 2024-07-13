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
[ SWERVE ] Swerving your website...

 >>> WARNING! THIS IS BETA SOFTWARE FOR PREVIEW ONLY <<<

Usage: swerve [-mdhvq] [-w,--workers=<processes>] [--fastcgi=<ip:port>] [--http=<ip:port>] [--https=<ip:port>] [--log=<path>] [swerve.php]

-m,--monitor              Monitor source code and reload automatically
-d                        Run as daemon
-w,--workers=<processes>  Number of worker processes (default: auto)
--fastcgi=<ip:port>       IP and port for FastCGI server
--http=<ip:port>          IP and port for HTTP server (default: 127.0.0.1:8080)
--https=<ip:port>         IP and port for HTTPS server
--log=<path>              Log errors to file
-h,--help                 Display this help message
-v,--verbose              Increase logging verbosity, repeat for higher verbosity
-q,--quiet                Suppress all output
[swerve.php]              Full path to application php file
```
