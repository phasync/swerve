<?php

/*
 * What PHP-FPM's SAPI has and the CLI's has not, for an application that is virtualized (see
 * Swerve::virtualize(), which loads this file). The application's own definitions come first.
 */

use Swerve\Http\Superglobals;

if (!\function_exists('getallheaders')) {
    /** The headers of the request, as sent. */
    function getallheaders(): array
    {
        $headers = [];
        foreach (\phasync::getContext()->request->getHeaders() as $name => $values) {
            $headers[$name] = \implode(', ', $values);
        }

        return $headers;
    }
}

if (!\function_exists('apache_request_headers')) {
    function apache_request_headers(): array
    {
        return \getallheaders();
    }
}

if (!\function_exists('fastcgi_finish_request')) {
    /** End the response here: the request goes on, and what it outputs from now on is discarded. */
    function fastcgi_finish_request(): bool
    {
        $context = \phasync::getContext();
        if (!$context instanceof Superglobals) {
            return false;
        }
        while (\ob_get_level() > 0) {
            \ob_end_flush();
        }

        return $context->sapi->finish();
    }
}
