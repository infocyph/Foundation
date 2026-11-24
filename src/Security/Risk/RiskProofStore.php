<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security\Risk;

use Psr\Cache\CacheException as PoolException;
use Psr\SimpleCache\CacheException;

/**
 * One-time provider proof claims and short-lived opaque clearance tokens.
 * A successful provider response is not an authentication result.
 *
 * @api
 */
final readonly class RiskProofStore
{
    /** @throws \InvalidArgumentException */
    public function __construct(
        private RiskAuthority $authority,
        private string $provider,
        private int $proofSeconds = 360,
        private int $clearanceSeconds = 90,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $provider) !== 1
            || $proofSeconds < 300 || $proofSeconds > 3600
            || $clearanceSeconds < 1 || $clearanceSeconds > 120
        ) {
            throw new \InvalidArgumentException('Invalid risk proof policy.');
        }
    }

    /**
     * Call only after independent provider verification. The provider domain
     * is global across tenants and purposes using that provider. A wrong
     * password must not release this claim.
     */
    public function claimVerifiedProof(#[\SensitiveParameter] string $proof): ?bool
    {
        if ($proof === '' || strlen($proof) > 4096) {
            return false;
        }

        try {
            return $this->authority->atomic->setIfAbsent(
                $this->authority->key('proof', $this->provider, $proof),
                1,
                $this->proofSeconds,
            );
        } catch (CacheException|PoolException) {
            return null;
        }
    }

    /**
     * Exchange an independently verified proof for one opaque clearance.
     * The proof stays spent even if clearance creation fails.
     *
     * @throws \InvalidArgumentException
     * @throws \Random\RandomException
     */
    public function issueClearance(
        RiskContext $context,
        string $account,
        #[\SensitiveParameter]
        string $verifiedProof,
        int $now,
    ): ?string {
        $binding = $this->binding($context, $account, $now);
        if ($this->claimVerifiedProof($verifiedProof) !== true) {
            return null;
        }

        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $stored = $this->authority->atomic->setIfAbsent(
                $this->authority->key('clearance', $token),
                ['binding' => $binding, 'expires' => $now + $this->clearanceSeconds],
                $this->clearanceSeconds,
            );

            return $stored ? $token : null;
        } catch (CacheException|PoolException) {
            return null;
        }
    }

    /**
     * Consume one clearance before password work. No non-atomic read/delete.
     * Wrong scope, expiry and replay cannot authorize hashing.
     *
     * @throws \InvalidArgumentException
     */
    public function redeemClearance(
        RiskContext $context,
        string $account,
        #[\SensitiveParameter]
        string $token,
        int $now,
    ): ?bool {
        $binding = $this->binding($context, $account, $now);
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
            return false;
        }

        try {
            $stored = $this->authority->atomic->getAndDelete($this->authority->key('clearance', $token));
        } catch (CacheException|PoolException) {
            return null;
        }
        if ($stored === null) {
            return false;
        }
        if (!is_array($stored) || !isset($stored['binding'], $stored['expires'])
            || !is_string($stored['binding']) || !is_int($stored['expires'])
        ) {
            return null;
        }

        return $stored['expires'] > $now
            && hash_equals($binding, $stored['binding']);
    }

    /** @throws \InvalidArgumentException */
    private function binding(RiskContext $context, string $account, int $now): string
    {
        if ($account === '' || strlen($account) > 256 || $now < 0
            || $now > PHP_INT_MAX - $this->clearanceSeconds
        ) {
            throw new \InvalidArgumentException('Invalid clearance scope.');
        }

        return $this->authority->key(
            'clearance-binding',
            $this->provider,
            $context->tenant,
            $context->purpose,
            $account,
            $context->prefix,
            $context->ja4 ?? '',
        );
    }
}
