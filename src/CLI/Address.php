<?php

namespace Swerve\CLI;

/**
 * An address to listen on, as given to --http or --fastcgi:
 *
 * - `8080`: port 8080 on 127.0.0.1, this machine only
 * - `:8080`: port 8080 on every IPv4 interface (0.0.0.0)
 * - `127.0.0.1:8080`, `0.0.0.0:8080`, `[::1]:8080`, `[::]:8080`
 * - `localhost:8080`: a host name, resolved once, at start
 */
final class Address
{
    /**
     * The address as ip:port, or [ipv6]:port.
     *
     * @throws \InvalidArgumentException with what is wrong with it
     */
    public static function normalize(string $value): string
    {
        if (\ctype_digit($value)) {
            $value = "127.0.0.1:$value";
        } elseif (\str_starts_with($value, ':')) {
            $value = "0.0.0.0$value";
        }
        if (!\preg_match('/^(?:\[([^\]]*)\]|([^:\[\]]*)):([^:]*)$/D', $value, $m)) {
            throw new \InvalidArgumentException('a port (8080), :port for every interface, or host:port ([ipv6]:port) required');
        }
        if (false === \filter_var($m[3], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
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
