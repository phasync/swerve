# Changelog

## Unreleased

- Pub/sub's reader, the limited services' listener and the WebSocket pings start on first use again, which may be inside a request, instead of with every worker's lifecycle: phasync 2.0.7's `phasync::service()` is started by the event loop, so it belongs to no request and keeps none alive (#102). A worker that never uses them starts none.

## 0.2.0-RC3 (2026-10-30)

- `Swerve::upgrade($handler)` runs the handler in the request, which waits for it: the handler has the request's code, objects, `Scope` and state for as long as the connection is open, `upgrade()` returns once it closed, and code after the call runs then. Any callable will do, a closure capturing what it likes. This reverses RC2's run after the handler returned, in a `Scope` of its own (#8): an open WebSocket keeps its request's state. A `swerve.php` that accepts one request per run, as WordPress's, is busy for as long as an inline WebSocket stays open, and so is a PSR-7 `101` there, which used to be refused.
- `Swerve::upgrade($handler, $bootstrap)` moves a WebSocket to swerve's WebSocket workers for that bootstrap file: they load it once as they start, in the global scope, with pub/sub, the cache and the container's limits shared with every worker. Each file has its own, up to one per worker, and every WebSocket a worker moves with a file goes to the same one. The handler is the name of a function or `[class, static method]`, or a static closure when phasync/serializor is installed. What decided before was the `swerve.php`: one that accepted once moved every WebSocket, with only the Composer autoload in the WebSocket worker.
- A bootstrap file that fails is logged as critical; its WebSockets are closed, and it is tried again a second later. A handler the file doesn't define closes its WebSocket, logged as critical, and the others go on.
- phasync/serializor is no longer required, only suggested.
- A `WebSocket` and the `Accepted` from `Swerve::accept()` are the app's to use as flags: swerve waited on and raised them itself. Each message raised `$ws`, waking any coroutine of the app waiting on it, and raising a request made `await()` return before the request was done.

## 0.2.0-RC2 (2026-10-30)

Needs only stable releases: phasync 2.0.7 (with ext-phasync 2.0.7, ext-scope 0.6.0 and fiber-interop/scope 0.5.0) and phasync/net 2.0.2. A request runs in its connection's coroutine, and nothing that serves requests is started inside one.

- A request whose response ended (Content-Length, `fastcgi_finish_request()`, or its handler returned) runs its tail work to the end in its coroutine and `Scope`; once that work waits, a spare coroutine, reused, goes on with the connection. What `Swerve::accept()` returned is ready only once that work ended: one `accept()` per run, or awaiting each request, waits for it; an `accept()` loop serves the connection's next request meanwhile. Static files are answered with no `Scope`. (#102)
- Nothing that serves the requests is started by a request any more, which kept that request alive (its `Scope`, superglobals, and a container slot it held) for as long as the service ran: pub/sub's reader, the limited services' listener and the WebSocket pings start with the worker's lifecycle and wait until a request needs them; pub/sub hands messages to its subscriptions without a coroutine per topic. A limited `Swerve::container()` service may be added inside a request. (#102)
- `Swerve::upgrade()`'s closure runs once the handler returned, in its connection's coroutine and a `Scope` of its own, no longer nested in the request: the request is freed while a WebSocket stays open. (#8)
- The limited services' releases go to one pub/sub topic for all of them. During a reload, workers of the old and the new code don't hear each other's releases, and fall back to looking again each second.

- A request's `$_SERVER` holds no environment variables, as under php-fpm's default `clear_env`: `getenv()` reads them. Each request copied the worker's whole environment, and an upgraded connection kept its copy for its life: with 50 variables, 16 KiB of its 52 KiB. (#8)
- An upgraded connection no longer keeps the array its request's `$_SERVER` was built from: 8 KiB less per raw upgrade or WebSocket. (#8)

## 0.2.0-RC1 (2026-10-29)

0.2 runs any PHP application. The master accepts every connection and hands each request to the worker that asks first; a worker runs `swerve.php`, which takes requests with `Swerve::accept()`: every request as it comes, one at a time with the application kept loaded, or one per run, starting over clean as under php-fpm. A request is PHP as under php-fpm (its own superglobals, session, `header()`, `echo`, `php://input`, shutdown functions), and the handler is a script, a closure, or a PSR-7 closure. `swerve -S [dir] [router.php]` serves a directory as `php -S` does. `Swerve::upgrade()` takes a connection off a request for Server-Sent Events, a WebSocket (moved to a WebSocket worker when `swerve.php` accepts once) or the raw `ClientRequest`. `RequestHandler`, the adapters, `--public`, the watchdog and the 0.1 command-line options other than `--http`, `--workers`, `--log`, `--grace`, `--watch` and `--pubsub-ring` are gone; ext-phasync is required, and loaded by swerve itself.

- A response is complete once its declared Content-Length is written, or at `Swerve::finishRequest()`. `fastcgi_finish_request()` and `litespeed_finish_request()` are defined to it when PHP lacks them. Coroutines the request started may go on running: the connection leaves the request and goes on as after any other (closed after `Connection: close`, else its next request), while their output is dropped and `connection_aborted()` is true for them. (#98)
- A process holds at most its open-file limit less 64 connections. At that limit the master closes the oldest connection still waiting for its request before it accepts another, and a worker closes one waiting on its client (answered, then reading a request head, kept alive, last waiting for a request body) before it takes another, with a warning at most once a minute. Without it a client holding connections locked everyone else out, silently. (#103)
- A PSR-7 handler's `101 Switching Protocols` response to an Upgrade request takes the connection: the 101 goes out with its headers, the response body is copied to the connection, and the request body is what the client sends after the 101. In a swerve.php that accepts once, it throws a LogicException (500). (#99)
- When the worker stops, a subscription's loop always throws a `WorkerStoppingException`, as documented: it could end as if its topic had ended, and an event stream fed by it then ended with its final chunk instead of being cut. `Topics::drain()` takes the stop reason. (#105)
- Container's release listener starts when a service is added, not inside the first request that gets one: that request's state was kept alive for the worker's lifetime, and its container slot never released. (#102)

## 0.1.0 (2026-10-19)

The first release.
