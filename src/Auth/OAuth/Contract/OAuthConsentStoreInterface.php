<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;

interface OAuthConsentStoreInterface
{
    public function find(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent;

    public function findActive(string $accountId, string $clientId, string $scopeFingerprint): ?OAuthConsent;

    public function revoke(string $accountId, string $clientId, int $revokedAt): int;

    public function save(OAuthConsent $consent): void;
}
