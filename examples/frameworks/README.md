# Frameworks booted once per worker

Each directory holds the `swerve.php` that keeps one framework loaded in the worker between
requests. Copy it into the root of your project, next to `vendor/`, and run:

```bash
vendor/bin/swerve swerve.php
```

| Directory | Framework | Needs |
|---|---|---|
| [laravel](laravel/swerve.php) | Laravel 13 (verified with 13.42, Octane 2.24) | `composer require laravel/octane` for its reset listeners; its servers and `octane:install` are not used |
| [symfony](symfony/swerve.php) | Symfony 8 (verified with 8.1) | nothing: the kernel resets its services between requests |
| [slim](slim/) | Slim 4 (verified with 4.15), and any PSR-15 application | `app.php` returns the application, `public/index.php` runs it under php-fpm or `swerve -S` |

Each `swerve.php` serves one request at a time per worker, so a request never shares the
application with another. [Running an existing application](../../docs/applications.md#boot-once)
explains why, what boot-once needs from your own code, and the measurements.
