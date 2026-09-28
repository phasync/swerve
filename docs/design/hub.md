# Design: the swerve binary (a hub for PHP workers)

Status: **proposal**, not implemented. Written 2026-09-27 from a design discussion; nothing here
is decided until the prototype (phase 1) has been measured.

## Summary

Swerve becomes a native executable (Rust) that owns every client connection and spawns plain
PHP worker processes. The binary speaks the network protocols (HTTP/1.1, HTTP/2, HTTP/3,
FastCGI, TLS), supervises the workers, carries publish/subscribe between them, and keeps a
shared key/value store. Each worker has one channel to the binary, over which requests, raw
streams, pub/sub messages, store operations and control messages travel as frames.

PHP applications don't change: they are PSR-15 request handlers, use `Swerve::publish()` and
`Swerve::subscribe()`, and run in phasync coroutines, thousands per worker.

## Why

Measured with today's swerve (56 workers, "Hello, World", `wrk` from another machine;
2026-09-27):

| Load | today, no ext | today, ext | Duplex port, ext (`tcp_server()`) |
|---|---|---|---|
| keep-alive, 64 connections | 173k req/s | 169k | 162k |
| keep-alive, 16,384 connections | 86k (1,063 timeouts) | 88k (19 timeouts) | 112k (0 timeouts) |
| 4 workers, 64 connections + 3,000 idle | 16% below no idle | 13% below | 0.6% below |

These figures predate phasync 2.0.0-alpha11 and a tuned network; current ones are in
[benchmarks/](../../benchmarks/).

What limits today's model, and what the in-process `tcp_server()` of phasync-ext only partly
fixes:

- **Idle connections cost every worker on every tick**: the event loop hands every waiting
  socket to `stream_select()`. With `tcp_server()` one stream carries them all, and the cost is
  gone; the binary keeps that property for every worker, without the extension.
- **Workers own their connections**: a reload, a recycle, a watchdog kill or a PHP fatal error
  ends the connections the worker held. Graceful reloads need a choreography of `SO_REUSEPORT`
  listeners, draining, and `net.ipv4.tcp_migrate_req`; `tcp_server()` can't stop listening
  alone yet (phasync/phasync-ext#4).
- **The kernel assigns connections** by a hash, not by how busy a worker is, and a returning
  client (a Tether tab reconnecting) can't be sent back to the worker holding its state.
- **HTTP is parsed in PHP**, now the largest cost per request in a worker; HTTP/2, HTTP/3 and
  TLS are out of reach in PHP.
- **Shared state** (sessions, caches, locks, presence) needs an external Redis or the database.

## Process model

```
            clients (HTTP/1.1, HTTP/2, HTTP/3, FastCGI; TLS)
                                 │
                    ┌────────────▼────────────┐
                    │      swerve (Rust)      │  listeners, TLS, HTTP parsing
                    │  supervisor · broker ·  │  static files, compression, access log
                    │   store · connection    │  pub/sub broker, key/value store
                    │          table          │
                    └──┬───────┬───────┬──────┘
             one channel per worker (Unix socket pair, frames)
                  ┌────▼──┐ ┌──▼────┐ ┌▼──────┐
                  │  php  │ │  php  │ │  php  │   phasync, coroutines, the application
                  └───────┘ └───────┘ └───────┘
```

- `swerve` is the process the user starts. It binds the listeners, then spawns N workers
  (`php <bundled worker script> <application file>`), each with one inherited socket pair.
- A worker is a plain PHP process. It needs no extension: one socket per worker is cheap with
  plain streams. phasync-ext still helps the application's own I/O (MySQL through mysqlnd),
  so "identical with and without the extension" holds by construction.
- The binary replaces today's PHP master: supervision, the watchdog, reload, recycling (max
  requests, max memory), logging, the command line, `--watch`.
- The binary is multi-threaded (tokio): every client byte passes through it, and at today's
  rates that is on the order of a million socket operations a second.

## The worker protocol

One channel per worker, frames in both directions. Proposed shape (to be settled by the
prototype): a fixed header `type:u8 id:u64 len:u32` little-endian, as phasync-ext's
`tcp_server()` uses, then `len` bytes. PHP parses one `unpack()` per frame; reads return many
frames at once.

### Streams and ids

Every client-facing stream has a 64-bit id, never reused during the binary's lifetime:

- a **request stream**: one HTTP request and its response (HTTP/1.1, HTTP/2 and HTTP/3 alike;
  an HTTP/2 connection carries many);
- a **raw stream**: the bytes of a connection after an upgrade (`101`, `CONNECT`), for protocols
  the binary doesn't know;
- a **message stream**: a WebSocket, framed by the binary (phase 4), so that workers send and
  receive whole messages.

### Requests

The binary parses HTTP and sends the worker structured frames; PHP parses no HTTP.

- binary → worker: request head (method, target, protocol version, headers, peer and local
  addresses, TLS facts), then body frames, then end of body. Bodies stream: a worker reads
  them as they arrive, and a worker that isn't reading pauses the client (backpressure).
- worker → binary: response head (status, headers), body frames, end. `101` turns the request
  stream into a raw or message stream.
- Limits and timeouts that swerve enforces today in PHP (header size, body size, slow clients,
  idle keep-alive) move to the binary.

### Ownership: who may write

- A **request stream** is written only by the worker the request was given to. The binary
  enforces it: a response from anyone else would corrupt HTTP.
- A **raw or message stream** may be written by any worker. Each write frame goes out whole
  (never interleaved with another writer's), in the order frames reach the binary; one
  worker's frames keep their order.
- Workers are trusted (the same application); ownership is about consistency, not security.

### Moving work between workers

- **Requests move freely at their boundaries**: every request on a keep-alive HTTP/1.1
  connection, and every HTTP/2 stream, is assigned on its own, to the least busy worker, not
  to one that drains. A reload or a recycle closes no client connection: old workers finish
  what they have, new work goes to new workers.
- **Raw and message streams move by explicit hand-off**, since their state lives in a worker's
  memory: the worker releases stream 4711 (`release`), another adopts it (`attached` event to
  the adopting worker). Tether can then remount a tab in a new worker without the browser
  reconnecting: a deploy barely shows.
- **Affinity**: the binary can route a returning client (by a cookie or a stream key the
  worker chose) to the worker that holds its state.

### Writing to many clients at once

- **To a list**: one frame, one payload, many stream ids. The worker encodes once and sends
  once; the binary fans out, with one write per client, in native code.
- **To a group**: streams join and leave named groups (`room:873`); one frame "send to group"
  reaches every member in every worker. Pub/sub delivered straight to sockets, as Phoenix
  channels or Socket.IO rooms do: chat messages, presence, notifications, one LLM answer
  streamed to many viewers, over WebSocket or Server-Sent Events.
- With WebSocket framing in the binary, the payload is a message, framed per recipient.
- **Slow recipients** have their own bounded buffer; one that falls too far behind is closed,
  never holding the others up (the rule of `Swerve::subscribe()`'s `$maxLag` today).
- Tether's rendered HTML differs per tab (component ids, per-tab state), so fan-out suits data
  and messages more than Tether's page patches.

### Publish/subscribe

`Swerve::publish()` and `Swerve::subscribe()` keep their API and promises (order per topic,
at most once, no history, lag limit); the binary is the broker, as the PHP master is today.

### The store

A shared key/value store in the binary, reached over the same channel (no network hop):

- get / set with TTL / delete / atomic increment / compare-and-set / locks with a TTL;
- notifications when a key changes or expires (for presence: a key per online user with a
  TTL refreshed by a heartbeat; its expiry announces the user left);
- PHP side: a PSR-16 `CacheInterface`, a session handler (mini's session backend), a lock API
  (the seeding race between workers becomes one call), rate limiting.
- In memory first: a restart of the binary empties it, and the docs must say so. Snapshots or
  an append-only log later if needed. Optionally the Redis protocol on a port, for other tools.
- One machine: several machines need a real Redis or similar, as publish/subscribe does.

### Control

Heartbeats (the watchdog), drain and stop, log lines from workers, metrics (requests in
flight, memory), and reload signals.

## What applications see

Nothing new is required:

- `swerve.php` returns a PSR-15 request handler, as today.
- `Swerve::publish()`, `Swerve::subscribe()`, `Swerve::log()`, `Swerve::draining()` as today.
- New APIs are additions: the store, groups and fan-out, stream hand-off, message streams.
- phasync/net's `Duplex` stays the API for raw streams; its transport becomes the channel.

## Risks and costs

- **The extra hop**: every byte crosses one more process boundary (two copies through the
  kernel). Batching makes it cheap per request; the "hello world" benchmark will show it.
  Whether it costs less than the HTTP parsing it removes from PHP is what phase 1 measures.
  Shared-memory rings (memfd and eventfd) could remove the copies later.
- **A single point of failure**: a crash of the binary ends every connection. Rust's safety
  helps; supervision of the binary itself (systemd) is the answer beyond that.
- **Upgrading the binary** without dropping connections needs socket hand-off to the new
  process (file descriptors passed over a Unix socket, as nginx does for binary upgrades).
- **The binary becomes the product**: HTTP/2, HTTP/3 and TLS edge cases are security-critical,
  maintained in Rust next to phasync.
- **Distribution**: prebuilt binaries per platform (Linux x86-64 and arm64, glibc and musl;
  macOS), installed through Composer as phasync-ext's are.
- **Two architectures** while today's PHP-only swerve is kept as a fallback; the proposal is to
  retire it once the binary covers its features.

## Phases

Each is measured before the next starts.

1. **Prototype**: Rust (tokio, hyper): HTTP/1.1 parsed in the binary, request frames to N PHP
   workers, round-robin; a PHP transport in swerve turning frames into a `ServerRequest` and
   responses into frames. Benchmarked against the table above.
2. **Parity with today's master**: supervision, reload, recycling, watchdog, publish/subscribe,
   logging, the command line. Replaces the PHP master.
3. **The store**: PSR-16, sessions, locks, TTL notifications (presence for Tether).
4. **Protocols**: TLS, HTTP/2, then HTTP/3; WebSocket framing in the binary; groups and
   fan-out; stream hand-off; FastCGI.

## Open questions

- The frame header: the `tcp_server()` layout as is, or with a flags byte and a stream kind.
- One channel per worker, or a second one for bulk data (large bodies, file uploads).
- How the store's operations look from PHP: synchronous calls that wait on the channel (a
  coroutine waits, the worker doesn't), matched to replies by a request id.
- Whether the binary serves static files by itself (`--public`), freeing workers entirely.
- Naming: the executable stays `swerve`; the Composer package that ships binaries.
