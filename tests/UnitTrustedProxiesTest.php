<?php

use Swerve\Http\TrustedProxies;

test('TrustedProxies: addresses, ranges of IPv4 and IPv6, and unix', function () {
    $p = new TrustedProxies(['10.1.2.3', '192.168.0.0/16', '172.16.0.0/12', 'fd00::/8', '2001:db8::1', '0.0.0.0/0']);
    expect($p->trusts('10.1.2.3'))->toBeTrue();
    expect($p->trusts('192.168.77.1'))->toBeTrue();
    expect($p->trusts('172.31.255.255'))->toBeTrue();
    expect($p->trusts('fd12:3456::1'))->toBeTrue();
    expect($p->trusts('2001:db8::1'))->toBeTrue();
    expect($p->trusts('2001:db8::2'))->toBeFalse();
    expect($p->trusts('::1'))->toBeFalse(); // an IPv6 address is not in an IPv4 range
    expect($p->trusts(''))->toBeFalse(); // a unix socket's client, unless unix is named

    $p = new TrustedProxies(['10.1.2.3', '172.16.0.0/12']);
    expect($p->trusts('10.1.2.4'))->toBeFalse();
    expect($p->trusts('172.32.0.1'))->toBeFalse();
    expect($p->trusts('11.1.2.3'))->toBeFalse();

    expect((new TrustedProxies(['unix']))->trusts(''))->toBeTrue();
    expect((new TrustedProxies(['unix']))->trusts('10.1.2.3'))->toBeFalse();
    expect((new TrustedProxies(['0.0.0.0/0']))->trusts('203.0.113.9'))->toBeTrue();
});

test('TrustedProxies: what is not an address or a range is refused', function (string $spec) {
    expect(fn () => new TrustedProxies([$spec]))->toThrow(InvalidArgumentException::class);
})->with(['proxy', '10.0.0.0/33', '10.0.0.0/x', 'fd00::/129', '10.0.0/8', '']);
