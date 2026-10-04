<?php

namespace Swerve\Http;

/**
 * The proxies whose X-Forwarded-For, -Proto and -Host headers are believed (`--trusted-proxy`): the
 * peers of the connection that are addresses or ranges of the list, and the clients of a unix
 * socket, which only a proxy on the machine can be.
 *
 * @internal
 */
final class TrustedProxies
{
    /** @var list<array{0: string, 1: int}> the packed address and the number of bits that count */
    private array $ranges = [];

    private bool $unix = false;

    /**
     * @param list<string> $specs an IP address, an `address/bits` range, or `unix`
     *
     * @throws \InvalidArgumentException for anything else
     */
    public function __construct(array $specs)
    {
        foreach ($specs as $spec) {
            if ('unix' === $spec) {
                $this->unix = true;
                continue;
            }
            [$address, $bits] = \explode('/', $spec, 2) + [1 => null];
            $packed           = \filter_var($address, \FILTER_VALIDATE_IP) ? \inet_pton($address) : false;
            if (false === $packed) {
                throw new \InvalidArgumentException("$spec is not an IP address, a range such as 10.0.0.0/8, or unix");
            }
            $max = 8 * \strlen($packed);
            if (null !== $bits && (!\ctype_digit($bits) || (int) $bits > $max)) {
                throw new \InvalidArgumentException("$spec is not an IP address, a range such as 10.0.0.0/8, or unix");
            }
            $this->ranges[] = [$packed, null === $bits ? $max : (int) $bits];
        }
    }

    /** @param string $address the peer's IP address; '' for the client of a unix socket */
    public function trusts(string $address): bool
    {
        if ('' === $address) {
            return $this->unix;
        }
        $packed = \inet_pton($address);
        foreach ($this->ranges as [$range, $bits]) {
            if (\strlen($range) !== \strlen($packed)) {
                continue;
            }
            $whole = $bits >> 3;
            if (\substr($range, 0, $whole) !== \substr($packed, 0, $whole)) {
                continue;
            }
            $rest = $bits & 7;
            if (0 === $rest || ((\ord($range[$whole]) ^ \ord($packed[$whole])) >> (8 - $rest)) === 0) {
                return true;
            }
        }

        return false;
    }
}
