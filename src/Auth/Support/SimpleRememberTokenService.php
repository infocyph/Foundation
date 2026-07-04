<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberToken;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenVerificationResult;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;

final readonly class SimpleRememberTokenService implements RememberTokenServiceInterface
{
    public function __construct(
        private RememberTokenStoreInterface $store,
        private ClockInterface $clock,
        private int $ttlSeconds = 2592000,
    ) {}

    public function issue(string $_accountId, string $_deviceId): RememberToken
    {
        unset($_accountId, $_deviceId);

        $selector = bin2hex(random_bytes(8));
        $verifier = bin2hex(random_bytes(24));
        $familyId = bin2hex(random_bytes(16));

        return new RememberToken(
            value: $selector . '.' . $verifier,
            selector: $selector,
            familyId: $familyId,
            verifierHash: hash('sha256', $verifier),
            expiresAt: $this->clock->now() + $this->ttlSeconds,
        );
    }

    public function verify(string $token): RememberTokenVerificationResult
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return new RememberTokenVerificationResult(false, failureReason: 'invalid_remember_token');
        }

        [$selector, $verifier] = $parts;
        $record = $this->store->findBySelector($selector);
        if ($record === null) {
            return new RememberTokenVerificationResult(false, failureReason: 'remember_token_not_found');
        }

        $verified = hash_equals($record->verifierHash, hash('sha256', $verifier));
        if (!$verified) {
            return new RememberTokenVerificationResult(
                verified: false,
                record: $record,
                suspiciousReuse: true,
                failureReason: 'remember_token_verifier_mismatch',
            );
        }

        return new RememberTokenVerificationResult(true, $record);
    }
}
