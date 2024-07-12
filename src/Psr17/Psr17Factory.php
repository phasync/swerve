<?php
namespace Swerve\Psr17;

use Charm\Http\Message\Response;
use Charm\Http\Message\Stream;
use phasync\Psr\ResourceStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

class Psr17Factory implements 
    ResponseFactoryInterface,
    StreamFactoryInterface
{
    public function createResponse(int $code = 200, ?string $reasonPhrase = null): ResponseInterface {
        return new Response('', statusCode: $code, reasonPhrase: $reasonPhrase);
    }

    public function createStream(string $content = ''): StreamInterface {
        return Stream::cast($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface {
        return $this->createStreamFromResource(fopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface {
        return new ResourceStream($resource);
    }
}
