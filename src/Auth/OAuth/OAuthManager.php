<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth;

use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeIssue;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
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
        private ?OAuthAuditRecorder $audit = null,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function authorizationRedirectContext(array $parameters): AuthorizationRedirectContext
    {
        return $this->authorizationRequests->redirectContext($parameters);
    }

    /** @param array<string, mixed> $parameters */
    public function validateAuthorizationRequest(array $parameters): AuthorizationRequest
    {
        try {
            return $this->authorizationRequests->validate($parameters);
        } catch (OAuthProtocolException $exception) {
            $this->audit?->record(
                AuthEventType::OAUTH_INVALID_REQUEST,
                metadata: ['error' => $exception->error],
                severity: AuthEventSeverity::WARNING,
            );
            throw $exception;
        }
    }

    public function hasConsent(PrincipalInterface $principal, AuthorizationRequest $request): bool
    {
        return $this->consents->hasConsent($principal, $request);
    }

    public function grantConsent(PrincipalInterface $principal, AuthorizationRequest $request): OAuthConsent
    {
        $consent = $this->consents->grant($principal, $request);
        $this->audit?->record(AuthEventType::OAUTH_AUTHORIZATION_APPROVED, $principal->accountId(), [
            'client_id' => $request->client->clientId,
            'scopes' => $request->scopes,
            'audiences' => $request->audiences,
        ]);

        return $consent;
    }

    public function revokeConsent(PrincipalInterface $principal, string $clientId): int
    {
        $count = $this->consents->revoke($principal, $clientId);
        if ($count > 0) {
            $this->audit?->record(AuthEventType::OAUTH_AUTHORIZATION_REVOKED, $principal->accountId(), [
                'client_id' => $clientId,
                'reason' => 'consent_revoked',
            ]);
        }

        return $count;
    }

    public function approve(AuthorizationRequest $request, PrincipalInterface $principal): OAuthAuthorizationCodeIssue
    {
        $issue = $this->authorizationCodes->issue($request, $principal);
        $this->audit?->record(AuthEventType::OAUTH_AUTHORIZATION_CODE_ISSUED, $principal->accountId(), [
            'client_id' => $request->client->clientId,
            'authorization_id' => $issue->authorization->id,
        ]);

        return $issue;
    }

    /** @param array<string, mixed> $parameters */
    public function exchange(array $parameters, OAuthClientAuthentication $authentication): OAuthTokenResponse
    {
        try {
            $response = $this->tokens->exchange($parameters, $authentication);
        } catch (OAuthProtocolException $exception) {
            $type = $exception->error === 'invalid_client'
                ? AuthEventType::OAUTH_CLIENT_AUTH_FAILURE
                : AuthEventType::OAUTH_INVALID_REQUEST;
            $this->audit?->record($type, metadata: [
                'client_id' => $authentication->clientId,
                'grant_type' => is_string($parameters['grant_type'] ?? null) ? $parameters['grant_type'] : null,
                'error' => $exception->error,
            ], severity: AuthEventSeverity::WARNING);
            throw $exception;
        }

        $this->audit?->record(AuthEventType::OAUTH_CLIENT_AUTH_SUCCESS, metadata: [
            'client_id' => $authentication->clientId,
            'grant_type' => is_string($parameters['grant_type'] ?? null) ? $parameters['grant_type'] : null,
        ]);
        $this->audit?->record(AuthEventType::OAUTH_ACCESS_TOKEN_ISSUED, metadata: [
            'client_id' => $authentication->clientId,
            'grant_type' => is_string($parameters['grant_type'] ?? null) ? $parameters['grant_type'] : null,
            'scopes' => $response->scope === '' ? [] : preg_split('/\s+/', $response->scope) ?: [],
            'token_type' => $response->tokenType,
        ]);

        return $response;
    }

    public function revoke(
        #[\SensitiveParameter] string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $this->revocations->revoke($token, $authentication, $tokenTypeHint);
        $this->audit?->record(AuthEventType::OAUTH_ACCESS_TOKEN_REVOKED, metadata: [
            'client_id' => $authentication->clientId,
            'token_type' => $tokenTypeHint,
        ]);
    }

    public function introspect(
        #[\SensitiveParameter] string $token,
        OAuthClientAuthentication $authentication,
    ): OAuthIntrospectionResult {
        $result = $this->introspection->introspect($token, $authentication);
        $this->audit?->record(AuthEventType::OAUTH_INTROSPECTION, metadata: [
            'client_id' => $authentication->clientId,
            'active' => $result->active,
            'token_type' => $result->tokenType,
        ]);

        return $result;
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
