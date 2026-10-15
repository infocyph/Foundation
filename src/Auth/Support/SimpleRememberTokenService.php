<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Epicrypt\Security\RememberToken as EpicryptRememberToken;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberToken;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenVerificationResult;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;

final readonly class SimpleRememberTokenService implements RememberTokenServiceInterface
{
    private EpicryptRememberToken $native;

    public function __construct(
        private RememberTokenStoreInterface $store,
        private ClockInterface $clock,
        private int $ttlSeconds,
        AuthSecretResolver $secrets,
    ) {
        if (!class_exists(EpicryptRememberToken::class)) {
            throw new \RuntimeException('Remember-me token signing requires the optional infocyph/epicrypt package.');
        }

        $this->native = new EpicryptRememberToken($secrets->tokenSecret(32), $this->ttlSeconds, new RememberTokenPsrClock($clock));
    }

    public function issue(string $accountId, string $deviceId): RememberToken
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = $this->native->issue($accountId, $deviceId);
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

        if (!$this->native->verify($verifier, $record->accountId, $record->deviceId)) {
            return new RememberTokenVerificationResult(false, record: $record, failureReason: 'invalid_native_remember_token');
        }

        return new RememberTokenVerificationResult(true, $record);
    }
}
