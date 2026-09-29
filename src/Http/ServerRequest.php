<?php

namespace Swerve\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use phasync\Psr\ServerRequest as Message;

/**
 * A request in HTTP mode: its form data is parsed when first asked for, see FormBody.
 *
 * Its clones (every with...() makes one) share the FormBody, so the body is parsed once, by
 * whichever clone asks first. A clone given its own parsed body, uploaded files or body with
 * withParsedBody(), withUploadedFiles() or withBody() keeps that.
 */
final class ServerRequest extends Message
{
    private bool $parsedBodySet = false;
    private bool $uploadedFilesSet = false;
    private bool $bodySet = false;

    /**
     * @param FormBody|null $form the form to parse on demand; null when PHP would not parse the body
     */
    public function __construct(string $method, string $requestTarget, StreamInterface $body, array $headers, array $serverParams, string $protocolVersion, private readonly ?FormBody $form)
    {
        parent::__construct($method, $requestTarget, $body, $headers, null, $serverParams, protocolVersion: $protocolVersion);
    }

    /** The cookies of a Cookie header, as PHP fills $_COOKIE: the first of equal names wins, values are URL-decoded. */
    public static function cookies(string $header): array
    {
        $cookies = [];
        foreach (\explode(';', $header) as $pair) {
            if (false !== ($eq = \strpos($pair, '='))) {
                $cookies[\trim(\substr($pair, 0, $eq), " \t")] ??= \urldecode(\trim(\substr($pair, $eq + 1), " \t"));
            }
        }

        return $cookies;
    }

    public function getParsedBody()
    {
        return null !== $this->form && !$this->parsedBodySet ? $this->form->fields() : parent::getParsedBody();
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $c                = parent::withParsedBody($data);
        $c->parsedBodySet = true;

        return $c;
    }

    public function getUploadedFiles(): array
    {
        return null !== $this->form && !$this->uploadedFilesSet ? $this->form->files() : parent::getUploadedFiles();
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $c                   = parent::withUploadedFiles($uploadedFiles);
        $c->uploadedFilesSet = true;

        return $c;
    }

    /** Before parsing, the body read from the connection; after, as php://input would be. */
    public function getBody(): StreamInterface
    {
        return null !== $this->form && !$this->bodySet ? $this->form->input() : parent::getBody();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        $c          = parent::withBody($body);
        $c->bodySet = true;

        return $c;
    }
}
