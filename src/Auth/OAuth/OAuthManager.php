<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth;

use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeIssue;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionResult;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthTokenResponse;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class OAuthManager
{
    public function __construct(
        private AuthorizationRequestValidator $authorizationRequests,
        private ConsentManager $consents,
        private AuthorizationCodeManager $authorizationCodes,
        private OAuthTokenManager $tokens,
        private OAuthRevocationManager $revocations,
        private OAuthIntrospectionManager $introspection,
        private AuthorizationServerMetadata $metadata,
        private JwkSetProviderInterface $jwks,
        private OAuthClientManager $clients,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function validateAuthorizationRequest(array $parameters): AuthorizationRequest
    {
        return $this->authorizationRequests->validate($parameters);
    }

    public function hasConsent(PrincipalInterface $principal, AuthorizationRequest $request): bool
    {
        return $this->consents->hasConsent($principal, $request);
    }

    public function grantConsent(PrincipalInterface $principal, AuthorizationRequest $request): OAuthConsent
    {
        return $this->consents->grant($principal, $request);
    }

    public function revokeConsent(PrincipalInterface $principal, string $clientId): int
    {
        return $this->consents->revoke($principal, $clientId);
    }

    public function approve(AuthorizationRequest $request, PrincipalInterface $principal): OAuthAuthorizationCodeIssue
    {
        return $this->authorizationCodes->issue($request, $principal);
    }

    /** @param array<string, mixed> $parameters */
    public function exchange(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        return $this->tokens->exchange($parameters, $authentication);
    }

    public function revoke(
        #[\SensitiveParameter] string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $this->revocations->revoke($token, $authentication, $tokenTypeHint);
    }

    public function introspect(
        #[\SensitiveParameter] string $token,
        OAuthClientAuthentication $authentication,
    ): OAuthIntrospectionResult {
        return $this->introspection->introspect($token, $authentication);
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata->toArray();
    }

    /** @return array{keys:list<array<string, mixed>>} */
    public function jwks(): array
    {
        return $this->jwks->jwks();
    }

    public function clients(): OAuthClientManager
    {
        return $this->clients;
    }
}
