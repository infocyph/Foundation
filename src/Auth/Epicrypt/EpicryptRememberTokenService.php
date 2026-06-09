<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Epicrypt;

use Infocyph\AuthLayer\Authentication\RememberMe\RememberToken;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberTokenServiceInterface;
use Infocyph\AuthLayer\Authentication\RememberMe\RememberTokenVerificationResult;
use Infocyph\AuthLayer\Contract\Storage\RememberTokenStoreInterface;

final readonly class EpicryptRememberTokenService implements RememberTokenServiceInterface
{
    private const string CONTEXT = 'auth.remember';

    public function __construct(
        private EpicryptTokenFactory $factory,
        private RememberTokenStoreInterface $store,
        private int $ttlSeconds = 2592000,
    ) {}

    public function issue(string $accountId, string $deviceId): RememberToken
    {
        $expiresAt = $this->factory->now() + $this->ttlSeconds;
        $selector = bin2hex(random_bytes(8));
        $verifier = bin2hex(random_bytes(24));
        $familyId = bin2hex(random_bytes(16));

        $value = $this->factory->payload(self::CONTEXT)->encode([
            'did' => $deviceId,
            'fam' => $familyId,
            'pur' => 'remember',
            'sel' => $selector,
            'sub' => $accountId,
            'ver' => $verifier,
        ], $this->factory->key(), [
            'exp' => $expiresAt,
        ]);

        return new RememberToken(
            value: $value,
            selector: $selector,
            familyId: $familyId,
            verifierHash: hash('sha256', $verifier),
            expiresAt: $expiresAt,
        );
    }

    public function verify(string $token): RememberTokenVerificationResult
    {
        $result = $this->factory->payload(self::CONTEXT)->verifyResult($token, $this->factory->key());
        if (!$result->verified) {
            return new RememberTokenVerificationResult(
                false,
                failureReason: $result->expired ? 'expired_remember_token' : 'invalid_remember_token',
            );
        }

        $claims = $result->claims;
        if (($claims['pur'] ?? null) !== 'remember') {
            return new RememberTokenVerificationResult(false, failureReason: 'invalid_remember_token');
        }

        $selector = is_string($claims['sel'] ?? null) ? $claims['sel'] : null;
        $verifier = is_string($claims['ver'] ?? null) ? $claims['ver'] : null;
        if ($selector === null || $verifier === null) {
            return new RememberTokenVerificationResult(false, failureReason: 'invalid_remember_token');
        }

        $record = $this->store->findBySelector($selector);
        if ($record === null) {
            return new RememberTokenVerificationResult(false, failureReason: 'remember_token_not_found');
        }

        if (
            !hash_equals($record->verifierHash, hash('sha256', $verifier))
            || !hash_equals($record->accountId, (string) ($claims['sub'] ?? ''))
            || !hash_equals($record->familyId, (string) ($claims['fam'] ?? ''))
            || !hash_equals($record->deviceId, (string) ($claims['did'] ?? ''))
        ) {
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
