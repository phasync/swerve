# Requests and responses

`Swerve::accept($staticPath, $handler)` takes one request. Files under `$staticPath` are answered
without the application; the rest goes to `$handler`:

| `$handler` | the request | the response |
|---|---|---|
| a script's path | a fresh main script, its variables globals | `header()`, `echo` |
| a closure without parameters | superglobals, `php://input` | `header()`, `echo` |
| a closure taking a PSR-7 `ServerRequestInterface` | built from the superglobals, its body `php://input` | the `ResponseInterface` it returns |
| `null` | | `404`, or the PHP file under `$staticPath` that the path names |

A script's path also runs the PHP files under `$staticPath` that a request names, as php-fpm
behind nginx does. Every request runs under the phasync extension's `sapi()` (`PHP_SAPI` is
`swerve`), in a `Scope` of its own: `Scope::getCurrent()` is per request, and the coroutines it
starts share it.

## The request

As under php-fpm: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, `$_SERVER` and `$_SESSION`
are the request's own, and `php://input` is its body.

- `$_SERVER` has what php-fpm gives a script (`REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`,
  `SCRIPT_NAME`, `SCRIPT_FILENAME`, `DOCUMENT_ROOT`, `PATH_INFO`, the `HTTP_*` headers, ...). As
  php-fpm's default `clear_env`, it holds no environment variables: `getenv()` reads them.
- A POST form (`application/x-www-form-urlencoded`, `multipart/form-data`) fills `$_POST` and
  `$_FILES` under `php.ini`'s limits (`post_max_size`, `upload_max_filesize`, `max_file_uploads`,
  `max_input_vars`).
- `php://input` reads the body as it arrives: a large upload never has to fit in memory.
- A client may keep a read waiting 10 s, plus a second for each KiB it sends; past that the read
  fails, so a client trickling a byte at a time can't hold a request for long.
- `Expect: 100-continue` is answered when the body is first read.

## The response

`header()`, `setcookie()`, `http_response_code()` and `echo` make it, through PHP's output
buffers as usual. `flush()` puts what is written on the wire: a stream is output with waits
between it. Without a `Content-Length` the body is chunked.

The response is complete when the handler returns, when its body has reached the
`Content-Length` it declared, or at `Swerve::finishRequest()` (also `fastcgi_finish_request()` and
`litespeed_finish_request()`). From then on the connection goes on without the request: its
next request is served while the request's code and the coroutines it started run on. They see
`connection_aborted()` true, and what they write is dropped.

A client that left makes `connection_aborted()` true as well; output then ends the script unless
`ignore_user_abort()` is set, as PHP does.

## PSR-7 handlers

```php
$factory = new phasync\Psr\PsrFactory();

while (Swerve::accept('public/', function (ServerRequestInterface $request) use ($factory): ResponseInterface {
    $response = $factory->createResponse(200)->withHeader('Content-Type', 'text/plain');
    $response->getBody()->write('Hello, ' . ($request->getQueryParams()['name'] ?? 'World'));

    return $response;
})) {
}
```

The request is made from the superglobals as a PSR-7 application's front controller makes it
under php-fpm: query, cookies, uploaded files, the parsed form, and `php://input` as its body.
The response body is copied out until its end, so a body that a coroutine writes after the
handler returned (a `phasync\Psr\UnbufferedStream`) streams through PSR-15 middleware.

A `101 Switching Protocols` response to a request with an `Upgrade` header takes the connection:
the 101 goes out with the response's headers, the response body is copied to the connection until
it ends, and the request body is what the client sends after the 101. It needs a `swerve.php`
that keeps calling `accept()`.

## Static files

`$staticPath` names a directory whose files are answered without the application: `/app.js` is
`<dir>/app.js`, and a directory is served by its `index.html`. They are streamed with
`Content-Type` (from the extension), `Content-Length`, `Last-Modified` and `ETag`, or as a
compressed copy beside them (`app.js.br`, `.zst`, `.gz`) to a client that accepts it; a
revalidation gets `304 Not Modified`, and a `Range` request `206 Partial Content`. Swerve sets no
`Cache-Control`.

Everything else goes to the handler: paths that are not files, methods other than GET and HEAD,
names starting with a dot (`.env`; `.well-known/` is served), PHP files, and paths leading out of
the directory. Nothing is listed. A request for a file doesn't use up the `accept()`: it goes on
with the connection's next request.

## The ClientRequest

`Swerve::upgrade(static function (Swerve\ClientRequest $client) { ... })` takes the connection off
the request, for a closure that writes the response itself, as long as it likes: see
[Realtime](realtime.md). `ClientRequest` is a `phasync\Net\Duplex`: `read()` is the request body,
`write()` the response body.

| | |
|---|---|
| `getMethod()` | `'GET'`, `'POST'`, ... as sent |
| `getTarget()` | the request target as sent: `/path?query`, `*` or an absolute URI |
| `getProtocolVersion()` | `'1.0'` or `'1.1'` |
| `getRequestHeaders()` | `array<string, list<string>>`: lowercase names, values in order; `host` is among them |
| `peer()`, `local()` | the client's address, and ours |
| `read($max = 65536, $timeout = null)` | the next piece of the request body; `''` only at its end |
| `write($bytes, $timeout = null)` | the next piece of the response body |
| `sendResponseHeaders($status, $headers = [])` | see below |
| `headersSent()` | whether the final head is committed (it may still be held back) |
| `flush()` | commit the head and put it on the wire |
| `sendFile($stream, $offset = null, $length = null)` | write a file as the body |
| `end($trailers = null)` | finish the response |
| `close()` | abort the connection |

### The head

`sendResponseHeaders(int $status, array $headers = [], ?string $reason = null)`, with header values a
string or a list, and the standard reason phrase unless you give one:

- A `1xx` is an interim response: sent at once, and may be repeated (`103` Early Hints).
- `101` is sent at once and switches to the raw connection: `read()` returns first what the
  client sent behind its Upgrade request.
- Any other status is the final head. It is held until the first `write()`, `end()`, `flush()` or
  `sendFile()`, and goes out with the first body bytes in one packet. A second call throws
  `Swerve\HeadersSentException`.

Without a `sendResponseHeaders()`, the first `write()`, `end()` or `flush()` sends an implicit `200`
with no headers of yours.

### The body

`write()` goes straight out: nothing is buffered. `write('')` does nothing. Once the client is
gone, `write()` throws `phasync\IOException`.

- With a `content-length` the body is framed as it is: the response is complete once it is
  written, and writing more throws. Without one the body is chunked (HTTP/1.1), or ends with the
  connection (HTTP/1.0).
- A held head with no body at all, and no `content-length` or `transfer-encoding`, gets
  `content-length: 0`.
- A `transfer-encoding: chunked` is honoured; any other value, or one together with a
  `content-length`, is an error. `connection` and `keep-alive` are the module's: only `connection`
  options such as `upgrade` are passed on.
- `HEAD` requests, and `204`, `205` and `304`, get no body.
- The module adds `date`, and `server: Swerve` unless you send a `server`.

`sendFile($stream, $offset, $length)` writes a file, or part of one, as the body, and commits the
head first. No `content-length` is computed; send one with the head:

```php
$file = fopen($path, 'rb');
$client->sendResponseHeaders(200, ['content-type' => 'application/pdf', 'content-length' => (string) filesize($path)]);
$client->sendFile($file);
```

`end(?array $trailers)` finishes the response; the closure returning does it too. Trailers need a
chunked response: name them in a `trailer` header, and pass them to `end()`.

### Errors

- An exception before the head is committed gives a `500` and is logged. After, the connection is
  aborted, and it is logged.
- A malformed header (a name that is not a token, CR, LF or NUL in a value, an invalid
  `content-length`) throws `UnexpectedValueException` from `sendResponseHeaders()`.
- `read()`, `write()` and `flush()` throw `phasync\IOException` when the client is gone, and a
  write `phasync\TimeoutException` when the client stopped reading for 60 s.

## Connections

HTTP/1.1 keep-alive and pipelining are supported; a kept-alive connection may be idle for 5 s.
Limits, all answered with the proper status:

| | |
|---|---|
| request line | 8 KiB (414) |
| request head (line and headers) | 64 KiB and 100 headers (431), within 10 s (408) |
| each wait while reading a body or writing a response | 60 s |

A process holds at most its open-file limit less 64 connections. At that limit the connections
waiting on their client are closed to make room for new ones: those already answered first, then
those reading a request head, the kept-alive ones, and last those waiting for more of a request
body. A warning says so, at most once a minute.

## Logging

`Swerve::log()` is swerve's log as a PSR-3 `LoggerInterface`: lines go where swerve's go (stderr,
or `--log`'s file), with the time and the worker's process id. Notices and worse are written.

```php
Swerve::log()->warning('Payment {id} declined', ['id' => $id]);
Swerve::log()->error('Import failed: {exception}', ['exception' => $e]);
```

`{name}` placeholders are replaced from the context.

Next: [Realtime](realtime.md).
