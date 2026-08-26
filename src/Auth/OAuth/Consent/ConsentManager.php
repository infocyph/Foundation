<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Consent;

use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Contract\OAuthConsentStoreInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class ConsentManager
{
    public function __construct(
        private OAuthConsentStoreInterface $consents,
        private AuthorizerInterface $authorizer,
        private ClockInterface $clock,
    ) {}

    public function grant(PrincipalInterface $principal, AuthorizationRequest $request): OAuthConsent
    {
        $accountId = $this->accountId($principal);
        $this->assertPermissions($principal, $request);
        $fingerprint = $this->fingerprint($request->scopes, $request->audiences);
        $existing = $this->consents->find($accountId, $request->client->clientId, $fingerprint);
        if ($existing instanceof OAuthConsent && $existing->revokedAt === null) {
            return $existing;
        }

        $consent = new OAuthConsent(
            id: $existing instanceof OAuthConsent ? $existing->id : bin2hex(random_bytes(16)),
            accountId: $accountId,
            clientId: $request->client->clientId,
            scopeFingerprint: $fingerprint,
            scopes: $request->scopes,
            audiences: $request->audiences,
            grantedAt: $this->clock->now(),
            metadata: $existing instanceof OAuthConsent ? $existing->metadata : [],
        );
        $this->consents->save($consent);

        return $consent;
    }

    public function hasConsent(PrincipalInterface $principal, AuthorizationRequest $request): bool
    {
        $accountId = $this->accountId($principal);
        if (!$this->permissionsAllowed($principal, $request)) {
            return false;
        }

        return $this->consents->findActive(
            $accountId,
            $request->client->clientId,
            $this->fingerprint($request->scopes, $request->audiences),
        ) instanceof OAuthConsent;
    }

    public function revoke(PrincipalInterface $principal, string $clientId): int
    {
        return $this->consents->revoke($this->accountId($principal), $clientId, $this->clock->now());
    }

    private function accountId(PrincipalInterface $principal): string
    {
        $accountId = $principal->accountId();
        if (!is_string($accountId) || $accountId === '') {
            throw new \LogicException('OAuth user authorization requires an account principal.');
        }

        return $accountId;
    }

    private function assertPermissions(PrincipalInterface $principal, AuthorizationRequest $request): void
    {
        if (!$this->permissionsAllowed($principal, $request)) {
            throw new \LogicException('OAuth scope permission policy denied the authorization request.');
        }
    }

    /** @param list<string> $scopes @param list<string> $audiences */
    private function fingerprint(array $scopes, array $audiences): string
    {
        sort($scopes, SORT_STRING);
        sort($audiences, SORT_STRING);

        return hash('sha256', implode("\0", $scopes) . "\0\0" . implode("\0", $audiences));
    }

    private function permissionsAllowed(PrincipalInterface $principal, AuthorizationRequest $request): bool
    {
        return array_all($request->requiredPermissions, fn($permission) => $this->authorizer->can(
            $principal,
            $permission,
            context: [
                'oauth_client_id' => $request->client->clientId,
                'oauth_scopes' => $request->scopes,
                'oauth_audiences' => $request->audiences,
            ],
        )->allowed);
    }
}
