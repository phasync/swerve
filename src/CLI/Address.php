<?php

namespace Swerve\CLI;

/**
 * An address to listen on, as given to --http or --fastcgi:
 *
 * - `8080`: port 8080 on 127.0.0.1, this machine only
 * - `:8080`: port 8080 on every IPv4 interface (0.0.0.0)
 * - `127.0.0.1:8080`, `0.0.0.0:8080`, `[::1]:8080`, `[::]:8080`
 * - `localhost:8080`: a host name, resolved once, at start
 * - `unix:/run/swerve.sock`, `unix:///run/swerve.sock` or `/run/swerve.sock`: a Unix domain
 *   socket; a relative path is relative to the directory where swerve started
 */
final class Address
{
    /**
     * The address as ip:port, [ipv6]:port, or unix:/absolute/path.
     *
     * @throws \InvalidArgumentException with what is wrong with it
     */
    public static function normalize(string $value): string
    {
        if (\str_starts_with($value, 'unix:') || \str_starts_with($value, '/')) {
            $path = \preg_replace('#^unix:(?://)?#', '', $value);
            if ('' === $path || \str_ends_with($path, '/')) {
                throw new \InvalidArgumentException('a path to a socket file required');
            }
            if (!\str_starts_with($path, '/')) {
                $path = \getcwd() . '/' . $path;
            }
            // sockaddr_un.sun_path holds 108 bytes, with the terminating zero
            if (\strlen($path) > 107) {
                throw new \InvalidArgumentException('a socket path of at most 107 bytes required');
            }

            return "unix:$path";
        }
        if (\ctype_digit($value)) {
            $value = "127.0.0.1:$value";
        } elseif (\str_starts_with($value, ':')) {
            $value = "0.0.0.0$value";
        }
        if (!\preg_match('/^(?:\[([^\]]+)\]|([^:\[\]]*)):([^:]*)$/D', $value, $m)) {
            throw new \InvalidArgumentException('a port (8080), :port for every interface, or host:port ([ipv6]:port) required');
        }
        if ($m[3] !== (string) (int) $m[3] || $m[3] < 1 || $m[3] > 65535) {
            throw new \InvalidArgumentException('a port from 1 to 65535 required');
        }
        if ('' !== $m[1]) {
            if (false === \filter_var($m[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                throw new \InvalidArgumentException("$m[1] is not an IPv6 address");
            }

            return "[$m[1]]:$m[3]";
        }
        if (false === \filter_var($m[2], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $ip = \gethostbyname($m[2]);
            if ($ip === $m[2]) {
                throw new \InvalidArgumentException("can't resolve $m[2]");
            }
            $m[2] = $ip;
        }

        return "$m[2]:$m[3]";
    }
}
