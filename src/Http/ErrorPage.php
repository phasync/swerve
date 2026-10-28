<?php

namespace Swerve\Http;

use Swerve\ClientRequest;
use Swerve\Swerve;

/**
 * The body of a response swerve makes itself (a 404 of `swerve -S`, a refused request): a small
 * page for a browser, signed `Swerve/<version>` as nginx and Apache sign theirs, or one line of
 * text for any other client. Never details of the failure. An application's own error responses
 * are its own.
 */
final class ErrorPage
{
    private const MESSAGES = [
        400 => 'The server could not understand the request.',
        403 => 'You do not have permission to access this resource.',
        404 => 'The requested URL was not found on this server.',
        405 => 'The method is not allowed for this resource.',
        408 => 'The server timed out waiting for the request.',
        414 => 'The requested URL is too long.',
        417 => 'The server cannot meet the request\'s expectation.',
        431 => 'The request\'s header fields are too large.',
        500 => 'The server encountered an internal error and could not complete the request.',
        501 => 'The server does not support this request.',
        503 => 'The server is temporarily unable to handle the request. Please try again later.',
        505 => 'The HTTP version of the request is not supported.',
    ];

    private static ?string $signature = null;

    /**
     * Answer $client with the page for $status, in full.
     */
    public static function send(ClientRequest $client, int $status): void
    {
        [$type, $body] = self::render($status, HttpClientRequest::REASONS[$status] ?? '', self::acceptsHtml($client->getRequestHeaders()['accept'] ?? []));
        $client->sendResponseHeaders($status, ['Content-Type' => $type, 'Content-Length' => (string) \strlen($body)]);
        $client->write($body);
        $client->end();
    }

    /**
     * Whether a client sending these Accept headers takes HTML: a browser names text/html.
     *
     * @param list<string> $accept
     */
    public static function acceptsHtml(array $accept): bool
    {
        return \str_contains(\implode(',', $accept), 'text/html');
    }

    /**
     * @return array{0: string, 1: string} the Content-Type and the body
     */
    public static function render(int $status, string $reason, bool $html): array
    {
        $message = self::MESSAGES[$status] ?? '';
        if (!$html) {
            return ['text/plain; charset=utf-8', "$status $reason\n" . ('' === $message ? '' : "$message\n")];
        }
        $signature = self::$signature ??= 'Swerve/' . \htmlspecialchars(Swerve::getVersion());
        $reason  = \htmlspecialchars($reason);
        $message = '' === $message ? '' : "<p>$message</p>";

        return ['text/html; charset=utf-8', <<<HTML
            <!doctype html>
            <html lang="en">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="color-scheme" content="light dark">
            <title>$status $reason</title>
            <style>
            :root { --bg: #f7f7f8; --fg: #1d1d21; --muted: #6b6b75; --rule: #e2e2e6; }
            @media (prefers-color-scheme: dark) { :root { --bg: #131316; --fg: #ececf1; --muted: #8d8d98; --rule: #2a2a30; } }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
            main { box-sizing: border-box; width: 100%; max-width: 34rem; padding: 2rem 1.5rem; }
            h1 { margin: 0; font-size: clamp(3.5rem, 12vw, 5.5rem); font-weight: 700; line-height: 1; letter-spacing: -0.04em; font-variant-numeric: tabular-nums; }
            h2 { margin: 0.75rem 0 0; font-size: 1.25rem; font-weight: 600; }
            p { margin: 0.5rem 0 0; color: var(--muted); }
            footer { margin-top: 2.5rem; padding-top: 0.75rem; border-top: 1px solid var(--rule); font-size: 0.8125rem; color: var(--muted); }
            </style>
            <main>
            <h1>$status</h1>
            <h2>$reason</h2>
            $message
            <footer>$signature</footer>
            </main>

            HTML];
    }
}
