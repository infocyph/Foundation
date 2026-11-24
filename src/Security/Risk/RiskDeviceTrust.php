<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\Device\DeviceManager;
use Infocyph\Webrick\Response\Cookies\Cookie;
use Throwable;

/**
 * Device trust is score relief only, never an authentication credential.
 * A live DeviceManager lookup is required on every accepted token so
 * revocation and store failure cannot silently grant relief.
 *
 * @api
 */
final readonly class RiskDeviceTrust
{
    public const string COOKIE_NAME = '__Host-foundation-risk-device';

    /**
     * @param array<string,string> $keys Active and retained verification keys.
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function __construct(
        private DeviceManager $devices,
        #[\SensitiveParameter]
        private array $keys,
        private string $activeKey,
        bool $authoritativeDevices = false,
        private int $lifetimeSeconds = 604_800,
        private bool $bindFingerprint = false,
    ) {
        if (!$authoritativeDevices) {
            throw new \LogicException('Device trust requires an authoritative device store with shared revocation.');
        }
        if (count($keys) < 1 || count($keys) > 8 || !isset($keys[$activeKey])
            || $lifetimeSeconds < 1 || $lifetimeSeconds > 2_592_000
        ) {
            throw new \InvalidArgumentException('Invalid risk device policy.');
        }
        foreach ($keys as $id => $key) {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,31}\z/D', $id) !== 1 || strlen($key) < 32) {
                throw new \InvalidArgumentException('Invalid risk device signing key.');
            }
        }
    }

    /**
     * Mint only after actual authentication and an existing trusted device.
     * A successful challenge alone cannot create a device trust token.
     *
     * @throws \InvalidArgumentException
     */
    public function issueCookie(RiskContext $context, LoginResult $login, int $now): ?Cookie
    {
        $account = $login->principal?->accountId();
        $session = $login->session;
        $device = $session?->deviceId;
        if (!$login->authenticated() || $account === null || $session === null || $device === null
            || $session->accountId !== $account || $session->isExpiredAt($now)
            || !$this->scopeValid($context, $account, $device, $now)
            || !$this->trusted($account, $device)
        ) {
            return null;
        }

        $encodedDevice = self::encode($device);
        $head = 'v1.' . $this->activeKey . '.' . ($now + $this->lifetimeSeconds) . '.' . $encodedDevice;
        $signature = $this->signature($this->keys[$this->activeKey], $head, $context, $account);

        return Cookie::make(self::COOKIE_NAME, $head . '.' . $signature, secure: true, httpOnly: true, sameSite: 'Strict')
            ->maxAge($this->lifetimeSeconds);
    }

    /**
     * Verify the MAC and exact scope before a single live device lookup.
     * Store errors and revoked devices remove relief; callers still enforce
     * independent hard denial, replay and hash-capacity controls.
     */
    public function verify(
        RiskContext $context,
        string $account,
        #[\SensitiveParameter]
        string $token,
        int $now,
    ): bool {
        if (strlen($token) > 512 || $now < 0 || $now > PHP_INT_MAX - $this->lifetimeSeconds) {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 5 || $parts[0] !== 'v1' || !isset($this->keys[$parts[1]])
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $parts[2]) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{1,342}\z/D', $parts[3]) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $parts[4]) !== 1
        ) {
            return false;
        }
        $expires = filter_var($parts[2], FILTER_VALIDATE_INT);
        if (!is_int($expires) || $expires <= $now || $expires > $now + $this->lifetimeSeconds) {
            return false;
        }
        $device = base64_decode(strtr($parts[3], '-_', '+/'), true);
        if ($device === false || self::encode($device) !== $parts[3]
            || !$this->scopeValid($context, $account, $device, $now)
        ) {
            return false;
        }
        $head = implode('.', array_slice($parts, 0, 4));
        $expected = $this->signature($this->keys[$parts[1]], $head, $context, $account);

        return hash_equals($expected, $parts[4]) && $this->trusted($account, $device);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function scopeValid(RiskContext $context, string $account, string $device, int $now): bool
    {
        return $now >= 0 && $now <= PHP_INT_MAX - $this->lifetimeSeconds
            && $account !== '' && strlen($account) <= 256
            && $device !== '' && strlen($device) <= 256
            && (!$this->bindFingerprint || ($context->attested && $context->ja4 !== null));
    }

    private function signature(
        #[\SensitiveParameter]
        string $key,
        string $head,
        RiskContext $context,
        string $account,
    ): string {
        $material = "foundation-risk-device-v1\0";
        foreach ([$head, $context->tenant, $context->purpose, $account, $this->bindFingerprint ? ($context->ja4 ?? '') : ''] as $part) {
            $material .= pack('N', strlen($part)) . $part;
        }

        return self::encode(hash_hmac('sha256', $material, $key, true));
    }

    private function trusted(string $account, string $device): bool
    {
        try {
            return $this->devices->isTrustedForAccount($account, $device);
        } catch (Throwable) {
            return false;
        }
    }
}
