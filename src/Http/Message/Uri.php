<?php
namespace Swerve\Http\Message;

use Psr\Http\Message\UriInterface;
use JsonSerializable;
use InvalidArgumentException;

/**
 * Class simplifies working with uris
 */
class Uri implements JsonSerializable, UriInterface {
    use UriTrait;

    const SCHEME_PORTS = [
        'ftp' => 21,
        'ssh' => 22,
        'telnet' => 23,
        'smtp' => 25,
        'gopher' => 70,
        'finger' => 79,
        'http' => 80,
        'rtelnet' => 107,
        'pop3' => 110,
        'sftp' => 115,
        'nntp' => 119,
        'ntp' => 123,
        'imap' => 143,
        'snmp' => 161,
        'irc' => 194,
        'ldap' => 389,
        'smtpe' => 420,
        'https' => 443,
        'ftps' => 990,
        'imaps' => 993,
        'pop3s' => 995,
        'wins' => 1512,
        'rtmp' => 1935,
    ];

    public static function cast(mixed $uri): static {
        return new static($uri);
    }

	/**
     * @param string|UriInterface $uri An URI
	 */
	public function __construct(mixed $uri) {
        $this->UriTrait($uri);
	}

    /**
     * Resolve the next URL by using this as the base URL
     */
    public function navigateTo(UriInterface|string $nextUri): static {
        $nextUri = (string) $nextUri;
        if (
            \str_starts_with($nextUri, 'https://') ||
            \str_starts_with($nextUri, 'http://')
        ) {
            return self::cast($nextUri);
        }

        return $this->withPath($nextUri);
    }
}
