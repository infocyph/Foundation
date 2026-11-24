<?php

declare(strict_types=1);

namespace Infocyph;

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\Authentication\Login\LoginStatus;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Foundation\Auth\Device\DeviceRecord;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Support\InMemoryDeviceStore;
use Infocyph\Foundation\Auth\Support\InMemorySessionStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Security\Risk\RiskContext;
use Infocyph\Foundation\Security\Risk\RiskDeviceTrust;
use Infocyph\Webrick\Request\Support\IpCidr;
use Infocyph\Webrick\Response\Cookies\Cookie;

it('issues score-relief cookies only for authenticated and currently trusted devices', function (): void {
    $devices = new DeviceManager(new InMemoryDeviceStore(), new UidAuthIdGenerator(new ConfigRepository([])));
    $sessions = new InMemorySessionStore();
    $device = $devices->register('account', 'test', null)->device;
    \PHPUnit\Framework\Assert::assertInstanceOf(DeviceRecord::class, $device);
    $keys = ['old' => str_repeat('a', 32), 'new' => str_repeat('b', 32)];
    $trust = new RiskDeviceTrust($devices, $sessions, $keys, 'old', authoritativeDevices: true, lifetimeSeconds: 60);
    $context = RiskContext::fromTransport('tenant', 'login', '192.0.2.1');
    $session = new AuthSession('session', 'account', $device->id, 100, 100, 1000);
    $sessions->create($session);
    $login = new LoginResult(LoginStatus::AUTHENTICATED, new Principal('account', accountId: 'account'), $session);
    expect($trust->issueCookie($context, new LoginResult(LoginStatus::INVALID_CREDENTIALS), 100))->toBeNull()
        ->and($trust->issueCookie($context, $login, 100, $device->id))->toBeNull();
    $devices->trust($device->id);
    expect($trust->issueCookie($context, $login, 100))->toBeNull()
        ->and($trust->issueCookie($context, $login, 100, 'unverified-device'))->toBeNull();
    $cookie = $trust->issueCookie($context, $login, 100, $device->id);
    \PHPUnit\Framework\Assert::assertInstanceOf(Cookie::class, $cookie);
    $header = (string) $cookie;
    expect($header)->toContain('__Host-foundation-risk-device=', 'Secure', 'HttpOnly', 'SameSite=Strict', 'Path=/', 'Max-Age=60')
        ->not->toContain('Domain=');
    $token = explode(';', $header, 2)[0];
    $token = rawurldecode(substr($token, strlen(RiskDeviceTrust::COOKIE_NAME) + 1));
    expect($trust->verify($context, 'account', $token, 101))->toBeTrue()
        ->and($trust->verify($context, 'other', $token, 101))->toBeFalse()
        ->and($trust->verify(RiskContext::fromTransport('other', 'login', '192.0.2.1'), 'account', $token, 101))->toBeFalse()
        ->and($trust->verify($context, 'account', $token, 160))->toBeFalse()
        ->and($trust->verify($context, 'account', $token . 'x', 101))->toBeFalse();
    $rotation = new RiskDeviceTrust($devices, $sessions, $keys, 'new', authoritativeDevices: true, lifetimeSeconds: 60);
    expect($rotation->verify($context, 'account', $token, 101))->toBeTrue();
    $retired = new RiskDeviceTrust($devices, $sessions, ['new' => $keys['new']], 'new', authoritativeDevices: true, lifetimeSeconds: 60);
    expect($retired->verify($context, 'account', $token, 101))->toBeFalse();
    $sessions->revoke($session->id);
    expect($rotation->issueCookie($context, $login, 101, $device->id))->toBeNull()
        ->and($rotation->verify($context, 'account', $token, 101))->toBeTrue();
    $devices->revoke($device->id);
    expect($rotation->verify($context, 'account', $token, 101))->toBeFalse()
        ->and($rotation->issueCookie($context, $login, 101, $device->id))->toBeNull();
});

it('optionally binds score relief to an attested fingerprint rather than a claimed agent', function (): void {
    $devices = new DeviceManager(new InMemoryDeviceStore(), new UidAuthIdGenerator(new ConfigRepository([])));
    $sessions = new InMemorySessionStore();
    $device = $devices->register('account', null, null)->device;
    \PHPUnit\Framework\Assert::assertInstanceOf(DeviceRecord::class, $device);
    $devices->trust($device->id);
    $session = new AuthSession('session', 'account', $device->id, 100, 100, 1000);
    $sessions->create($session);
    $login = new LoginResult(LoginStatus::AUTHENTICATED, new Principal('account', accountId: 'account'), $session);
    $trust = new RiskDeviceTrust($devices, $sessions, ['key' => str_repeat('k', 32)], 'key', authoritativeDevices: true, bindFingerprint: true);
    $edges = [IpCidr::from('10.0.0.0/8')];
    $fingerprint = 't13d1516h2_8daaf6152771_02713d6af862';
    $direct = RiskContext::fromTransport('tenant', 'login', '192.0.2.1', $edges, [], [$fingerprint]);
    expect($trust->issueCookie($direct, $login, 100, $device->id))->toBeNull();
    $context = RiskContext::fromTransport('tenant', 'login', '10.0.0.1', $edges, ['192.0.2.1'], [$fingerprint], [], ['old-agent']);
    $cookie = $trust->issueCookie($context, $login, 100, $device->id);
    \PHPUnit\Framework\Assert::assertInstanceOf(Cookie::class, $cookie);
    $token = rawurldecode(substr(explode(';', (string) $cookie, 2)[0], strlen(RiskDeviceTrust::COOKIE_NAME) + 1));
    $same = RiskContext::fromTransport('tenant', 'login', '10.0.0.1', $edges, ['192.0.2.2'], [$fingerprint], [], ['new-agent']);
    $changed = RiskContext::fromTransport('tenant', 'login', '10.0.0.1', $edges, ['192.0.2.2'], ['t13d1516h2_8daaf6152771_02713d6af863']);
    expect($trust->verify($same, 'account', $token, 101))->toBeTrue()
        ->and($trust->verify($changed, 'account', $token, 101))->toBeFalse()
        ->and($trust->verify($direct, 'account', $token, 101))->toBeFalse();
});
