<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth;

use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionErrorCode;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionPolicy;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionRequirement;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdInteractionState;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdUserInfoProjector;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationCodeManager;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRedirectContext;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationRequestValidator;
use Infocyph\Foundation\Auth\OAuth\Authorization\AuthorizationProtocolResult;
use Infocyph\Foundation\Auth\OAuth\Authorization\OAuthAuthorizationCodeIssue;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClientManager;
use Infocyph\Foundation\Auth\OAuth\Consent\ConsentManager;
use Infocyph\Foundation\Auth\OAuth\Consent\OAuthConsent;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\Metadata\OpenIdMetadataProvider;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
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
        private ?OpenIdMetadataProvider $openIdMetadata = null,
        private ?OpenIdUserInfoProjector $openIdUserInfo = null,
        private ?OAuthAccessTokenValidator $openIdAccessTokens = null,
        private ?OpenIdInteractionPolicy $openIdInteractions = null,
        private ?ClockInterface $clock = null,
    ) {}

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
    public function authorizationProtocolResult(array $parameters): AuthorizationProtocolResult
    {
        try {
            return $this->authorizationRequests->evaluate($parameters);
        } catch (OAuthProtocolException $exception) {
            $this->recordInvalidRequest($exception, 'redirect_validation');

            throw $exception;
        }
    }

    /** @param array<string, mixed> $parameters */
    public function authorizationRedirectContext(array $parameters): AuthorizationRedirectContext
    {
        return $this->authorizationRedirectContextFor($parameters, $this->authorizationProtocolResult($parameters));
    }

    /** @param array<string, mixed> $parameters */
    public function authorizationRedirectContextFor(
        array $parameters,
        AuthorizationProtocolResult $result,
    ): AuthorizationRedirectContext {
        try {
            return $this->authorizationRequests->redirectContextFor($parameters, $result);
        } catch (OAuthProtocolException $exception) {
            $this->recordInvalidRequest($exception, 'redirect_validation');

            throw $exception;
        }
    }

    public function clients(): OAuthClientManager
    {
        return $this->clients;
    }

    public function deny(AuthorizationRequest $request, ?PrincipalInterface $principal = null): void
    {
        $this->audit?->record(
            AuthEventType::OAUTH_AUTHORIZATION_DENIED,
            $principal?->accountId(),
            [
                'client_id' => $request->client->clientId,
                'scopes' => $request->scopes,
                'audiences' => $request->audiences,
                'reason' => 'resource_owner_denied',
            ],
            AuthEventSeverity::WARNING,
        );
    }

    /** @param array<string, mixed> $parameters */
    public function exchange(
        array $parameters,
        OAuthClientAuthentication $authentication,
        #[\SensitiveParameter]
        ?string $dpopProof = null,
    ): OAuthTokenResponse {
        try {
            $response = $this->tokens->exchange($parameters, $authentication, $dpopProof);
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
            'scopes' => $response->scopes,
            'token_type' => $response->tokenType,
        ]);

        return $response;
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

    public function hasConsent(PrincipalInterface $principal, AuthorizationRequest $request): bool
    {
        return $this->consents->hasConsent($principal, $request);
    }

    public function introspect(
        #[\SensitiveParameter]
        string $token,
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

    /** @return array{keys:list<array<string, mixed>>} */
    public function jwks(): array
    {
        return $this->jwks->jwks();
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata->toArray();
    }

    public function openIdInteraction(
        AuthorizationRequest $request,
        ?PrincipalInterface $principal,
    ): OpenIdInteractionRequirement {
        if (!$this->openIdInteractions instanceof OpenIdInteractionPolicy
            || $request->openIdProtocol === null) {
            throw new \LogicException('OpenID Connect interaction policy is unavailable.');
        }

        $accountId = $principal?->accountId();
        $metadata = $principal?->metadata() ?? [];
        $authenticationTime = $metadata['auth_time'] ?? null;
        if (!is_int($authenticationTime) || $authenticationTime < 1) {
            $authenticationTime = is_string($accountId) && $accountId !== ''
                ? ($this->clock?->now() ?? time())
                : null;
        }
        $authenticationContext = $metadata['acr'] ?? null;
        $authenticationContext = is_string($authenticationContext) && $authenticationContext !== ''
            ? $authenticationContext
            : null;
        $authenticationMethods = $metadata['amr'] ?? [];
        $authenticationMethods = is_array($authenticationMethods)
            ? array_values(array_filter($authenticationMethods, is_string(...)))
            : [];

        $result = $this->openIdInteractions->evaluate(
            $request->openIdProtocol,
            new OpenIdInteractionState(
                subject: is_string($accountId) && $accountId !== '' ? $accountId : null,
                authenticationTime: $authenticationTime,
                authenticationContext: $authenticationContext,
                authenticationMethods: $authenticationMethods,
                consentRequired: $principal === null || !$this->hasConsent($principal, $request),
            ),
        );
        if ($result->error instanceof OpenIdInteractionErrorCode) {
            throw new OAuthProtocolException(
                $result->error->value,
                'The OpenID authorization interaction cannot proceed without user interaction.',
                400,
                true,
            );
        }

        return $result->requirement
            ?? throw new \LogicException('OpenID interaction returned no requirement.');
    }

    /** @return array<string, mixed> */
    public function openIdMetadata(): array
    {
        if (!$this->openIdMetadata instanceof OpenIdMetadataProvider) {
            throw new \LogicException('OpenID Connect is not enabled.');
        }

        return $this->openIdMetadata->toArray();
    }

    public function revoke(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $this->revocations->revoke($token, $authentication, $tokenTypeHint);
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

    /** @return array<string, mixed> */
    public function userInfo(
        #[\SensitiveParameter]
        string $token,
        string $audience,
        string $method,
        string $uri,
        #[\SensitiveParameter]
        ?string $dpopProof = null,
    ): array {
        if (!$this->openIdUserInfo instanceof OpenIdUserInfoProjector
            || !$this->openIdAccessTokens instanceof OAuthAccessTokenValidator) {
            throw new \LogicException('OpenID Connect is not enabled.');
        }

        try {
            $verified = $this->openIdAccessTokens->verifyResource(
                $token,
                $audience,
                $method,
                $uri,
                $dpopProof,
            );
        } catch (OAuthTokenException) {
            throw new OAuthProtocolException(
                'invalid_token',
                'The access token is invalid.',
                401,
            );
        }

        $account = $verified->account;
        if ($account === null || !in_array('openid', $verified->claims->scopes, true)) {
            throw new OAuthProtocolException(
                'insufficient_scope',
                'The access token does not grant OpenID UserInfo access.',
                403,
            );
        }

        return $this->openIdUserInfo->project(
            $account->id(),
            $verified->client->clientId,
            $verified->claims->scopes,
        );
    }

    /** @param array<string, mixed> $parameters */
    public function validateAuthorizationRequest(array $parameters): AuthorizationRequest
    {
        return $this->validateAuthorizationResult($this->authorizationProtocolResult($parameters));
    }

    public function validateAuthorizationResult(AuthorizationProtocolResult $result): AuthorizationRequest
    {
        try {
            return $this->authorizationRequests->validateResult($result);
        } catch (OAuthProtocolException $exception) {
            $reason = $exception->error === 'invalid_scope' ? 'scope_validation' : 'authorization_request';
            $this->recordInvalidRequest($exception, $reason);

            throw $exception;
        }
    }

    private function recordInvalidRequest(OAuthProtocolException $exception, string $reason): void
    {
        $this->audit?->record(
            AuthEventType::OAUTH_INVALID_REQUEST,
            metadata: [
                'error' => $exception->error,
                'reason' => $reason,
            ],
            severity: AuthEventSeverity::WARNING,
        );
    }
}
