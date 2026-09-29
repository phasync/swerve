<?php
// Swoole and OpenSwoole: a request as a nyholm ServerRequest, a PSR-7 response through the server's
// response object. Minimal and our own: the maintained bridge (chubbyphp/chubbyphp-swoole-request-
// handler) writes every body in chunks (Transfer-Encoding: chunked) and supports only ext-swoole.
// The same code serves both extensions: their request and response objects have the same shape.

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

function swoole_psr_request(object $request): ServerRequestInterface
{
    $server = \array_change_key_case($request->server, \CASE_UPPER);
    $uri = 'http://' . ($request->header['host'] ?? 'localhost') . $server['REQUEST_URI']
        . (isset($server['QUERY_STRING']) ? '?' . $server['QUERY_STRING'] : '');
    $psr = new ServerRequest(
        $server['REQUEST_METHOD'],
        $uri,
        $request->header ?? [],
        (string) $request->rawContent(),
        \substr($server['SERVER_PROTOCOL'] ?? 'HTTP/1.1', 5),
        $server,
    );
    if ($request->cookie) {
        $psr = $psr->withCookieParams($request->cookie);
    }
    if ($request->get) {
        $psr = $psr->withQueryParams($request->get);
    }
    if (null !== $request->post) {
        $psr = $psr->withParsedBody($request->post);
    }
    if ($request->files) {
        $psr = $psr->withUploadedFiles(swoole_psr_files($request->files));
    }

    return $psr;
}

function swoole_psr_files(array $files): array
{
    $out = [];
    foreach ($files as $key => $file) {
        $out[$key] = isset($file['tmp_name'])
            ? new UploadedFile($file['tmp_name'], $file['size'], $file['error'], $file['name'], $file['type'])
            : swoole_psr_files($file);
    }

    return $out;
}

function swoole_psr_emit(ResponseInterface $psr, object $response): void
{
    $response->status($psr->getStatusCode(), $psr->getReasonPhrase());
    foreach ($psr->getHeaders() as $name => $values) {
        $response->header($name, 1 === \count($values) ? $values[0] : $values);
    }
    $response->end((string) $psr->getBody());
}
