# Changelog

## 0.2.0-RC1 (2026-10-29)

0.2 runs any PHP application. The master accepts every connection and hands each request to the worker that asks first; a worker runs `swerve.php`, which takes requests with `Swerve::accept()`: every request as it comes, one at a time with the application kept loaded, or one per run, starting over clean as under php-fpm. A request is PHP as under php-fpm (its own superglobals, session, `header()`, `echo`, `php://input`, shutdown functions), and the handler is a script, a closure, or a PSR-7 closure. `swerve -S [dir] [router.php]` serves a directory as `php -S` does. `Swerve::upgrade()` takes a connection off a request for Server-Sent Events, a WebSocket (moved to a WebSocket worker when `swerve.php` accepts once) or the raw `ClientRequest`. `RequestHandler`, the adapters, `--public`, the watchdog and the 0.1 command-line options other than `--http`, `--workers`, `--log`, `--grace` and `--watch` are gone; ext-phasync is required, and loaded by swerve itself.

- A response is complete once its declared Content-Length is written, or at `Swerve::finishRequest()`. `fastcgi_finish_request()` and `litespeed_finish_request()` are defined to it when PHP lacks them. Coroutines the request started may go on running: the connection leaves the request and goes on as after any other (closed after `Connection: close`, else its next request), while their output is dropped and `connection_aborted()` is true for them. (#98)
- A process holds at most its open-file limit less 64 connections. At that limit the master closes the oldest connection still waiting for its request before it accepts another, and a worker closes one waiting on its client (answered, then reading a request head, kept alive, last waiting for a request body) before it takes another, with a warning at most once a minute. Without it a client holding connections locked everyone else out, silently. (#103)
- A PSR-7 handler's `101 Switching Protocols` response to an Upgrade request takes the connection: the 101 goes out with its headers, the response body is copied to the connection, and the request body is what the client sends after the 101. In a swerve.php that accepts once, it throws a LogicException (500). (#99)
- When the worker stops, a subscription's loop always throws a `WorkerStoppingException`, as documented: it could end as if its topic had ended, and an event stream fed by it then ended with its final chunk instead of being cut. `Topics::drain()` takes the stop reason. (#105)
- Container's release listener starts when a service is added, not inside the first request that gets one: that request's state was kept alive for the worker's lifetime, and its container slot never released. (#102)

## 0.1.0 (2026-10-19)

The first release.
