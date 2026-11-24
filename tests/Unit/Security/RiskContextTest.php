<?php

declare(strict_types=1);

namespace Infocyph;

use Fiber;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Webrick\Request\Support\IpCidr;
use InvalidArgumentException;

it('ignores forged edge signals on direct transport and normalizes mapped IPv4', function (): void {
    $context = RiskContext::fromTransport('tenant-a', 'login', '::ffff:192.0.2.43', [IpCidr::from('10.0.0.0/8')], ['203.0.113.8'], ['forged'], ['999'], ['Example/1, test']);
    expect($context->attested)->toBeFalse()
        ->and($context->ip)->toBe('192.0.2.43')
        ->and($context->prefix)->toBe('192.0.2.0/24')
        ->and($context->ja4)->toBeNull()
        ->and($context->asn)->toBeNull()
        ->and($context->userAgent)->toBe('Example/1, test');
});

it('accepts bounded single-valued signals only from a configured edge', function (): void {
    $context = RiskContext::fromTransport('tenant-a', 'login', '10.1.2.3', [IpCidr::from('10.0.0.0/8')], ['2001:db8:1234:5678::123'], ['t13d1516h2_8daaf6152771_02713d6af862'], ['4294967295']);
    expect($context->attested)->toBeTrue()
        ->and($context->ip)->toBe('2001:db8:1234:5678::123')
        ->and($context->prefix)->toBe('2001:db8:1234:5678::/64')
        ->and($context->ja4)->toBe('t13d1516h2_8daaf6152771_02713d6af862')
        ->and($context->asn)->toBe('4294967295');
    $withoutFingerprint = RiskContext::fromTransport('tenant-a', 'login', '10.1.2.3', [IpCidr::from('10.0.0.0/8')], ['192.0.2.1']);
    expect($withoutFingerprint->ja4)->toBeNull()->and($withoutFingerprint->asn)->toBeNull();
});

it('rejects missing ambiguous and malformed attested claims', function (): void {
    $edges = [IpCidr::from('10.0.0.0/8')];
    foreach ([
        [],
        ['192.0.2.1', '192.0.2.2'],
        ['192.0.2.1,192.0.2.2'],
        ['192.0.2.1:123'],
        ['fe80::1%eth0'],
    ] as $clients) {
        expect(fn() => RiskContext::fromTransport('tenant-a', 'login', '10.0.0.1', $edges, $clients))->toThrow(InvalidArgumentException::class);
    }
    foreach (['forged', str_repeat('a', 37)] as $ja4) {
        expect(fn() => RiskContext::fromTransport('tenant-a', 'login', '10.0.0.1', $edges, ['192.0.2.1'], [$ja4]))->toThrow(InvalidArgumentException::class);
    }
    foreach (['0', '01', '4294967296', '123,456'] as $asn) {
        expect(fn() => RiskContext::fromTransport('tenant-a', 'login', '10.0.0.1', $edges, ['192.0.2.1'], [], [$asn]))->toThrow(InvalidArgumentException::class);
    }
    expect(fn() => RiskContext::fromTransport('', 'login', '192.0.2.1'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => RiskContext::fromTransport('tenant-a', 'login', '192.0.2.1', userAgents: [str_repeat('a', 513)]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => RiskContext::fromTransport('tenant-a', 'login', '192.0.2.1', userAgents: ["agent\r\ninjected"]))->toThrow(InvalidArgumentException::class);
});

it('keeps risk scopes isolated across interleaved fibers', function (): void {
    $fiber = new Fiber(static function (): RiskContext {
        $context = RiskContext::fromTransport('tenant-a', 'login', '192.0.2.1');
        expect(Fiber::suspend())->toBe('resume');

        return $context;
    });
    $fiber->start();
    $other = RiskContext::fromTransport('tenant-b', 'oauth', '2001:db8::1');
    $fiber->resume('resume');
    $first = $fiber->getReturn();
    \PHPUnit\Framework\Assert::assertInstanceOf(RiskContext::class, $first);
    expect($first->tenant)->toBe('tenant-a')
        ->and($first->purpose)->toBe('login')
        ->and($other->tenant)->toBe('tenant-b')
        ->and($other->purpose)->toBe('oauth')
        ->and($first->prefix)->not->toBe($other->prefix);
});
