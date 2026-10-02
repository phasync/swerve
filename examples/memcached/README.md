# A memcached server inside swerve

A memcached server (text protocol) that every swerve worker runs, with `Swerve::cache()` as its
storage. It shows what [`swerve.php` can do besides returning a handler](../../docs/bootstrap.md):
start a server of your own in each worker. Two files: [`swerve.php`](swerve.php) and
[`MemcachedServer.php`](MemcachedServer.php).

## Run it

```
MEMCACHED_PORT=11211 vendor/bin/swerve --workers=4 examples/memcached/swerve.php
printf 'set a 0 0 1\r\nx\r\nget a\r\nstats\r\n' | nc localhost 11211
```

Any memcached client works (`new Memcached()` with `addServer('127.0.0.1', 11211)`), as long as
it speaks the text protocol. Swerve's own HTTP port shows the port and the counters of the worker
that answers.

## 1. Listen in every worker

`swerve.php` creates the server and starts it before it returns the handler. Each worker listens
on the same port with `SO_REUSEPORT`; the kernel gives every new connection to one of them.

```php
$context  = stream_context_create(['socket' => ['so_reuseport' => true, 'tcp_nodelay' => true, 'backlog' => 1024]]);
$listener = stream_socket_server("tcp://0.0.0.0:$port", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
stream_set_blocking($listener, false);
phasync::go($this->accept(...));
```

The accept loop is a coroutine. It starts running when `swerve.php` has returned, which is also
when the cache starts to work: see [what works where](../../docs/bootstrap.md#what-works-where).

## 2. A coroutine per connection

```php
while (!Swerve::draining()) {
    try {
        phasync::readable($this->listener, 0.25);          // wait for a connection, without blocking the worker
    } catch (TimeoutException) {
        continue;                                          // look at draining() again
    }
    while (false !== ($socket = @stream_socket_accept($this->listener, 0))) {
        stream_set_blocking($socket, false);
        phasync::go(fn () => $this->serve($socket, ++$id));
    }
}
```

`serve()` reads a command, answers it, and repeats. Reading is buffered: `fill()` waits with
`phasync::readable()` and appends 64 KiB at a time, `readLine()` cuts a line from the buffer,
and a storage command's data block is read with the length its command line gave (up to 1 MB).
While a connection waits, the worker serves the others. Replies are written with
`phasync::writable()` when the client's buffer is full.

## 3. Commands to the cache

Each value is stored as one entry, a list `[flags, bytes, cas, expires]`, so that the flags and
the raw bytes come back exactly as stored: the cache serializes it. `get` and `gets` read the
keys in one `getMultiple()`. `set`, `add`, `replace`, `append`, `prepend`, `cas`, `delete`,
`incr`, `decr`, `touch` and `flush_all` (`Swerve::cache()->clear()`) read and write entries;
`version` and `stats` answer from the worker. Replies follow memcached: `STORED`, `NOT_STORED`,
`EXISTS`, `NOT_FOUND`, `DELETED`, `TOUCHED`, `ERROR` for an unknown command or a wrong number of
arguments, `CLIENT_ERROR` for a bad argument or data block, `SERVER_ERROR` for a value over 1 MB
or a full cache. `noreply` suppresses the reply of a command, errors included. `quit` closes.

`exptime` is memcached's: 0 never, negative already expired, up to 30 days (2,592,000 s) from
now, beyond that a unix time. The entry carries its absolute expiry, which `append`, `prepend`,
`incr` and `decr` keep and `touch` changes; the cache's own TTL is set from it, in seconds.

## 4. Keys

The cache refuses keys with `{}()/\@:`; memcached keys may have them (up to 250 bytes, no spaces
or control characters, else `CLIENT_ERROR bad command line format`). The server stores a key
under `rawurlencode($key)`: it is one to one, and what it returns has none of those characters.

| memcached key | cache key |
|---|---|
| `user:1` | `user%3A1` |
| `a/b` | `a%2Fb` |
| `a%3Ab` | `a%253Ab` (not the same key as `a:b`) |

## 5. Atomic operations with claims

The cache has no compare-and-set, and workers do not share memory. A `Swerve::claim()` is a name
that one holder at a time can have across all workers, so each write takes the claim of its key:

```php
if (null === ($claim = Swerve::claim("mc:$key")->acquire(5.0))) {
    return "SERVER_ERROR key is busy\r\n";
}
try {
    return $fn();                       // read the entry, decide, write it
} finally {
    $claim->release();
}
```

What is and is not atomic:

- Every write command (`set`, `add`, `replace`, `append`, `prepend`, `cas`, `delete`, `incr`,
  `decr`, `touch`) is atomic against every other write to the same key, in any worker. So `cas`
  has one winner, `incr` and `append` lose no update, and `add` stores once.
- Reads do not take the claim. A `get` sees an entry as it was before or after a concurrent
  write, never a part of one. A multi-key `get` is not a snapshot of the keys.
- `flush_all` is not ordered against writes in progress.
- A write waits for the key's claim up to 5 s, polling every 20 ms while another holds it, then
  answers `SERVER_ERROR key is busy`. A worker that dies holding a claim loses it.
- `cas` ids are random 63-bit numbers, new on every write (a `set` of the same value too);
  `touch` keeps the id. No coordination is needed between the workers for that.
- `incr` and `decr` work on decimal values up to 2^63 - 1: more is `CLIENT_ERROR`. `decr`
  stops at 0. The result is not padded with spaces as memcached pads it.

## 6. Shutting down

When the worker drains (a reload, a restart, Ctrl-C), the accept loop ends and closes the
listener, the connections waiting for a command are cancelled, and a connection that is running
a command closes after it. The worker exits once its HTTP server has drained, which closes what
is left: clients reconnect, to another worker. The server does not hold up the reload.

## Limits

- The storage is the swerve cache: `--cache-size` (64 MiB) bounds it, the least recently used
  entries go first, a restart empties it, and `flush_all` clears all of it, also what the
  application keeps there.
- Storing a value is a round trip through the master. It is quick for small values, and slow
  for a large one: storing 1 MB takes seconds, during which writes to that key wait.
- `stats` shows the counters of the worker that answers, not of all.
- Not implemented: the binary protocol, a delay in `flush_all`, `verbosity`, `gat`, the meta
  commands and the `stats` subcommands (`stats <anything>` answers `END`).
