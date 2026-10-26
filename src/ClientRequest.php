<?php

namespace Swerve;

use phasync\Net\Duplex;

/**
 * One HTTP exchange with a client: the request to read, and the response to write. A request
 * gets it from {@see Swerve::upgrade()}, to answer on the connection itself, and may keep it for
 * as long as it likes (an event stream, a long download) by simply not returning.
 *
 * As a {@see Duplex}, read() is the request body ('' only once it ended) and write() the
 * response body; the module does the HTTP framing. The final response head is held until the
 * first write(), end(), flush() or sendFile(), and goes out with the first body bytes in one
 * packet; a response with no body and no content-length gets `content-length: 0`. No
 * sendResponseHeaders() before a write means an implicit `200` with no headers of yours.
 *
 * The module adds `date` and `server: Swerve` (unless you send a `server`), chooses the framing
 * and owns the hop-by-hop headers: a `content-length` frames the body as it is, otherwise it is
 * chunked (HTTP/1.1) or ends with the connection (HTTP/1.0). A `transfer-encoding` of `chunked`
 * is the same as none; any other value throws.
 *
 * ```php
 * while (Swerve::accept(null, function () {
 *     $request = Swerve::upgrade();
 *     $request->sendResponseHeaders(200, ['content-type' => 'text/event-stream']);
 *     foreach ([1, 2, 3] as $n) {
 *         $request->write("data: $n\n\n");
 *         $request->flush();
 *         phasync::sleep(1);
 *     }
 * })) {
 * }
 * ```
 *
 * A handler that throws before the head is committed makes a 500 (logged); after, the connection
 * is aborted. After a `101`, the ClientRequest is the raw connection, and read() returns first
 * what the client sent behind its Upgrade request.
 *
 * @see Swerve::upgrade()
 *
 * @topic request-handlers
 *
 * @example examples/ClientRequest/1-hello.php Read the request, write the response
 */
interface ClientRequest extends Duplex
{
    /** The request method, as sent: 'GET', 'POST', ... */
    public function getMethod(): string;

    /** The request target, as sent: '/path?query', '*' or an absolute URI. */
    public function getTarget(): string;

    /** '1.0' or '1.1'. */
    public function getProtocolVersion(): string;

    /** 'http' or 'https'; from a trusted proxy's `X-Forwarded-Proto` (see `--trusted-proxy`). */
    public function getScheme(): string;

    /**
     * The request headers: lowercase name => the values, in order. `host` is among them.
     *
     * @return array<string, list<string>>
     */
    public function getRequestHeaders(): array;

    /**
     * Set the response's status and headers.
     *
     * A `1xx` (`100`, `103`, ...) is an interim response: sent at once, and may be repeated. `101`
     * is sent at once and switches to the raw connection, `connection` and `upgrade` being yours
     * to send. Any other status is the final head: held until the body starts, and sendable once.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws HeadersSentException when the final head was committed already
     *
     * @example examples/ClientRequest.sendResponseHeaders/1-interim-and-final.php An interim 103 and a final 201
     */
    public function sendResponseHeaders(int $status, array $headers = []): void;

    /** Whether the final head is committed (it may still be held back until the first body bytes). */
    public function headersSent(): bool;

    /**
     * Commit the head (an implicit `200`) and put it on the wire, with nothing buffered.
     *
     * @example examples/ClientRequest.flush/1-progress.php Put the head on the wire before the work
     */
    public function flush(): void;

    /**
     * Write a file, or part of one, as the body: commits the head, then streams it as write()
     * does, under the same framing rules. No content-length is computed; send one with the head.
     *
     * @param resource $stream a readable stream, such as fopen()'s
     * @param int|null $offset where to start reading, null: where the stream is
     * @param int|null $length how many bytes, null: to the end
     *
     * @example examples/ClientRequest.sendFile/1-part-of-a-file.php Send part of a file
     */
    public function sendFile($stream, ?int $offset = null, ?int $length = null): void;

    /**
     * Finish the response. With no head sent, an implicit `200`.
     *
     * Trailers need a chunked response: name them in a `trailer` header, and give them here.
     *
     * @param array<string, string|list<string>>|null $trailers
     *
     * @throws \LogicException when there is a content-length, or the client is HTTP/1.0, or the response may not have them
     *
     * @example examples/ClientRequest.end/1-trailers.php Trailers after a chunked body
     */
    public function end(?array $trailers = null): void;
}
