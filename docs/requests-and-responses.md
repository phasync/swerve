# Requests and responses

Swerve gives your handler a PSR-7 `ServerRequestInterface` (its own implementation) and sends the
`ResponseInterface` it returns. This page is about HTTP mode, swerve's default; in FastCGI
mode (`--fastcgi`), the web server in front speaks HTTP.

## What a request carries

| | |
|---|---|
| `getMethod()`, `getUri()` | `http://host/path?query`: the Host header, and `http`, since swerve itself does not do TLS |
| `getHeaders()`, `getHeaderLine()` | as the client sent them |
| `getQueryParams()` | parsed from the query string |
| `getCookieParams()` | parsed from the Cookie header, as PHP does |
| `getServerParams()` | `REMOTE_ADDR`, `REMOTE_PORT`, `REQUEST_METHOD`, `REQUEST_URI`, `SERVER_PROTOCOL`, `REQUEST_TIME`, `REQUEST_TIME_FLOAT` |
| `getBody()` | the request body, read from the connection as you read it; see below |
| `getParsedBody()` | a POST form's fields, as PHP's `$_POST`: see *Forms* below; `null` for any other body |
| `getUploadedFiles()` | a multipart POST's files, as PHP's `$_FILES`, as PSR-7 `UploadedFileInterface` objects |

Behind a reverse proxy, `REMOTE_ADDR` is the proxy's address. The client's is in the header
the proxy sets, such as `X-Forwarded-For`; trust it only when the request came from your proxy.

## Forms

Swerve parses what PHP parses, and only that: a **POST** whose `Content-Type` is
`application/x-www-form-urlencoded` or `multipart/form-data`. Its fields and files are what PHP
would put in `$_POST` and `$_FILES`, down to how names like `a[b][]` nest and dots in names
become underscores. Every other body is left raw for you: a PUT, a JSON POST, anything else.
Decode JSON yourself, or with your framework (Slim: `$app->addBodyParsingMiddleware()`).

Parsing is lazy: the body is read on the first `getParsedBody()` or `getUploadedFiles()`, so a
handler that never asks costs nothing, and one that streams the body itself still can. Asking
for the parsed body after reading the raw body throws a `LogicException`: ask first. After
parsing, `getBody()` is what `php://input` would be: an url-encoded body's bytes, and nothing
for a multipart one.

PHP's limits from `php.ini` apply as in PHP: `post_max_size` (a larger body gives an empty form,
and a warning in the log), `upload_max_filesize` (a larger file gets `UPLOAD_ERR_INI_SIZE`),
`max_file_uploads`, `max_input_vars`, `max_multipart_body_parts`, `file_uploads` and
`enable_post_data_reading`. Uploads are streamed to temporary files in `upload_tmp_dir` and
deleted when the request is gone, unless moved with `moveTo()`. The one difference from PHP:
PHP keeps one field more than `max_input_vars`, swerve exactly that many.

Upgrade requests (WebSockets) are never parsed.

## The request body

The body is a stream connected to the socket: nothing is read before you read it, and a large
upload never has to fit in memory.

- `(string) $request->getBody()` or `getContents()` reads it whole; `read($n)` reads up to
  `$n` bytes at a time; `read()` returns `''` only at the end.
- It may be read after the response was returned, and from another coroutine. The next
  request on that connection waits until the body is read, or dropped.
- A body you never read is skipped (up to 64 KiB within 5 s), or the connection closes.
- Bodies larger than `--max-body` (8 MiB by default) get `413`.
- A client may keep your `read()` waiting 10 s, plus a second for each KiB it sends; past
  that, `read()` throws `phasync\TimeoutException` (a `RuntimeException`), so a client
  trickling a byte at a time can't hold your handler for long.
- `Expect: 100-continue` is answered when you first read the body, so a client only sends a
  body you actually want.
- A client that disconnects in the middle makes `read()` throw a `RuntimeException`.

## The response

Swerve sends the status line and headers, then reads the response body chunk by chunk and
sends each chunk as it comes, so a large or slow body is streamed, not buffered:

- A body with a known size gets a `Content-Length`; one without (a generator-backed or
  `UnbufferedStream`) is sent chunked, each piece as soon as it is read.
- `HEAD` requests get the headers only.
- A response body can be written while it is being sent: that is how Server-Sent Events work,
  see [Realtime](realtime.md).
- `--buffer-responses` reads each body whole first (up to 8 MiB) and sends it with one
  write. It helps only for many small responses with a slow body stream; it delays streaming
  responses until they end.

Swerve adds `Date`, and `Connection` as needed; it does not add `Content-Type`, compress, or
set caching headers.

## Connections

HTTP/1.1 keep-alive and pipelining are supported; a kept-alive connection may be idle for
5 s. Limits, all answered with the proper status:

| | |
|---|---|
| request line | 8 KiB (414) |
| request head (line and headers) | 64 KiB and 100 headers (431), within 10 s (408) |
| body | `--max-body`, 8 MiB (413) |
| each wait while reading a body or writing a response | 60 s |

A worker serves at most about 960 connections at once without phasync-ext (see
[Production](production.md#sizing)); past that, new connections wait in the kernel's queue
while connections that sit idle are closed to make room.

## Static files

`--public=<dir>` serves the files in a directory before your application sees the request:
`/app.js` is `<dir>/app.js`, `/` is `<dir>/index.html`. Everything else goes to your
application: paths that are not files, methods other than GET and HEAD, names starting with a
dot (`.env`; `.well-known/` is served), and paths leading out of the directory.

Files are streamed with `Content-Type` (from the extension), `Content-Length`,
`Last-Modified` and `ETag`; browsers' revalidations get `304 Not Modified`, and a `Range`
request `206 Partial Content`. Swerve sets no `Cache-Control`.

The same is PSR-15 middleware, `Swerve\StaticFiles`, for your own middleware stack:
`$app->add(new Swerve\StaticFiles(__DIR__ . '/public'))` in Slim.

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
