# Changelog

## Unreleased (0.2)

- A response is complete once its declared Content-Length is written, or at `Swerve::finishRequest()`. `fastcgi_finish_request()` and `litespeed_finish_request()` are defined to it when PHP lacks them. Coroutines the request started may go on running: the connection leaves the request and goes on as after any other (closed after `Connection: close`, else its next request), while their output is dropped and `connection_aborted()` is true for them. (#98)
- Container's release listener starts when a service is added, not inside the first request that gets one: that request's state was kept alive for the worker's lifetime, and its container slot never released. (#102)

## 0.1.0 (2026-10-19)

The first release.
