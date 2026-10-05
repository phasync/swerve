# Requests and responses

`swerve.php` returns a `Swerve\RequestHandler` wrapping a closure. Swerve calls the closure once for
each HTTP exchange with a `Swerve\ClientRequest`: the request to read and the response to write.
A closure is required; anything else makes the worker exit with code 2 and a message.

```php
return new Swerve\RequestHandler(function (Swerve\ClientRequest $request): void {
    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write("Hello, World\n");
});
```

The handler runs in the connection's own coroutine, in a phasync context of its own:
`phasync::getContext()` is per request, and `phasync::finally()` in a handler runs once the
exchange is finished. It may keep the request for as long as it likes (an event stream, a long
download) by not returning. When it returns, swerve finishes the exchange: an implicit `end()`,
and the unread request body is skipped (up to 64 KiB within 5 s) or the connection closed. The
request body can no longer be read after that.

Request data is not parsed for you: there is no PSR-7 request, no query parameters, cookies,
parsed body or uploaded files. Parse what you need from `getTarget()` and `getRequestHeaders()`,
and read the body. A framework is served through an adapter that turns the `ClientRequest` into the
framework's own request and response.

## The ClientRequest

`ClientRequest` extends `phasync\Net\Duplex`: `read()` is the request body, `write()` the response
body.

| | |
|---|---|
| `getMethod()` | `'GET'`, `'POST'`, ... as sent |
| `getTarget()` | the request target as sent: `/path?query`, `*` or an absolute URI |
| `getProtocolVersion()` | `'1.0'` or `'1.1'` |
| `getScheme()` | `'http'`, or `'https'` from a [trusted proxy](command-line.md)'s `X-Forwarded-Proto` |
| `getRequestHeaders()` | `array<string, list<string>>`: lowercase names, values in order; `host` is among them (a trusted proxy's `X-Forwarded-Host` replaces it) |
| `peer()`, `local()` | the client's address, and ours; behind a trusted proxy the client's is from `X-Forwarded-For` |
| `read($max = 65536, $timeout = null)` | the next piece of the request body; `''` only at its end |
| `write($bytes, $timeout = null)` | the next piece of the response body |
| `sendResponseHeaders($status, $headers = [])` | see below |
| `headersSent()` | whether the final head is committed (it may still be held back) |
| `flush()` | commit the head and put it on the wire |
| `sendFile($stream, $offset = null, $length = null)` | write a file as the body |
| `end($trailers = null)` | finish the response |
| `close()` | abort the connection |

## The request body

The body is connected to the socket: nothing is read before you read it, and a large upload never
has to fit in memory.

```php
$body = '';
while ('' !== ($piece = $request->read())) {
    $body .= $piece;
}
```

- `read()` returns `''` only at the end of the body. It throws `phasync\IOException` when the
  client vanished in the middle.
- Bodies larger than `--max-body` (8 MiB by default) get `413`.
- A client may keep your `read()` waiting 10 s, plus a second for each KiB it sends; past that,
  `read()` throws `phasync\TimeoutException`, so a client trickling a byte at a time can't hold your
  handler for long.
- `Expect: 100-continue` is answered when you first read the body, so a client only sends a body
  you actually want.

For a framework adapter (swerve-psr15, Symfony, Tether), `Swerve\Psr\RequestBody` is this body as a
PSR-7 stream, and `Swerve\Psr\FormBody` is the PHP-compatible form data parsed from it on demand
(`fields()`, `files()`, `input()`): a POST whose Content-Type is `application/x-www-form-urlencoded`
or `multipart/form-data` is parsed under PHP's own `php.ini` limits (`post_max_size`,
`upload_max_filesize`, `max_file_uploads`, `max_input_vars`, ...), exactly as `$_POST` and `$_FILES`
would be. Every adapter builds the same request body the same way; applications don't use these
directly.

## The response head

`sendResponseHeaders(int $status, array $headers = [])`, with header values a string or a list:

- A `1xx` is an interim response: sent at once, and may be repeated (`103` Early Hints).
- `101` is sent at once and switches to the raw connection: see
  [Realtime](realtime.md#raw-connections-and-websockets).
- Any other status is the final head. It is held until the first `write()`, `end()`, `flush()` or
  `sendFile()`, and goes out with the first body bytes in one packet. A second call throws
  `Swerve\HeadersSentException`.

Without a `sendResponseHeaders()`, the first `write()`, `end()` or `flush()` sends an implicit `200`
with no headers of yours.

## The response body

`write()` goes straight out: nothing is buffered, so a stream is just writes with waits between
them. `write('')` does nothing. Once the client is gone, `write()` throws `phasync\IOException`.

The module owns the framing and the hop-by-hop headers:

- With a `content-length` the body is framed as it is, and writing more than it throws. Without
  one the body is chunked (HTTP/1.1), or ends with the connection (HTTP/1.0). To send a body of
  known size, send its `content-length` yourself.
- A held head with no body at all, and no `content-length` or `transfer-encoding`, gets
  `content-length: 0`.
- A handler's `transfer-encoding: chunked` is honoured; any other value, or one together with a
  `content-length`, is an error (`500`). `connection` and `keep-alive` are the module's: only
  `connection` options such as `upgrade` are passed on.
- `HEAD` requests, and `204`, `205` and `304`, get no body.
- The module adds `date` to every response, including its own error responses, and `server: Swerve`
  unless the handler sets a `server`.

`flush()` commits the head, an implicit `200` when there is none, and puts it on the wire: a stream
the browser should see opening before the first event calls it.

`sendFile($stream, $offset, $length)` writes a file, or part of one, as the body under the same
framing rules, and commits the head first. No `content-length` is computed; send one with the head:

```php
$file = fopen($path, 'rb');
$request->sendResponseHeaders(200, ['content-type' => 'application/pdf', 'content-length' => (string) filesize($path)]);
$request->sendFile($file);
```

`end(?array $trailers)` finishes the response; the handler returning does it too. Trailers need a
chunked response: name them in a `trailer` header, and pass them to `end()`. With a `content-length`, on
HTTP/1.0 or with a status that has no body, `end()` throws `LogicException`.

## Errors

- A handler that throws before the head is committed gives a `500` and is logged. After, the
  connection is aborted, and it is logged.
- A malformed header (a name that is not a token, CR, LF or NUL in a value, an invalid
  `content-length`) throws `UnexpectedValueException` from `sendResponseHeaders()`.
- `read()`, `write()` and `flush()` throw `phasync\IOException` when the client is gone, and a
  write `phasync\TimeoutException` when the client stopped reading for 60 s. A producer that writes
  now and then (a keep-alive comment on an event stream) learns of a client that left at its next
  write; catch it to end the loop.
- Reading the request body after the response is finished throws `LogicException`.

## Connections

HTTP/1.1 keep-alive and pipelining are supported; a kept-alive connection may be idle for 5 s.
Limits, all answered with the proper status:

| | |
|---|---|
| request line | 8 KiB (414) |
| request head (line and headers) | 64 KiB and 100 headers (431), within 10 s (408) |
| body | `--max-body`, 8 MiB (413) |
| each wait while reading a body or writing a response | 60 s |

A worker serves at most 512 connections at once without phasync-ext (see
[Production](production.md#sizing)); past that, new connections wait in the kernel's queue
while connections that sit idle are closed to make room.

## Static files

`--public=<dir>` serves the files in a directory before your handler sees the request: `/app.js` is
`<dir>/app.js`, `/` is `<dir>/index.html`. Everything else goes to your handler: paths that are not
files, methods other than GET and HEAD, names starting with a dot (`.env`; `.well-known/` is
served), PHP files, and paths leading out of the directory.

Files are streamed with `Content-Type` (from the extension), `Content-Length`, `Last-Modified` and
`ETag`; browsers' revalidations get `304 Not Modified`, and a `Range` request `206 Partial Content`.
Swerve sets no `Cache-Control`.

`Swerve\StaticFiles` is the same without the option:

```php
$files = new Swerve\StaticFiles(__DIR__ . '/public');

return new Swerve\RequestHandler($files->wrap(function (Swerve\ClientRequest $request): void {
    // what is not a file
}));
```

`wrap(Closure $app): Closure` returns a handler that answers file requests and calls `$app` for the
rest.

## Logging

`Swerve::log()` is swerve's log as a PSR-3 `LoggerInterface`: lines go where swerve's go (the
terminal, or `--log`'s file), with the time and the worker's slot.

```php
Swerve::log()->warning('Payment {id} declined', ['id' => $id]);
Swerve::log()->error('Import failed: {exception}', ['exception' => $e]);
```

`{name}` placeholders are replaced from the context. Without `-v`, notices and worse are
written; `-v` adds info, `-vv` debug. Swerve also logs a line per request (`GET /path 200
1.2ms`, the time being the handler's) unless `--no-access-log`.

Next: [Realtime](realtime.md).
