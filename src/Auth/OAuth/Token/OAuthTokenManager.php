<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationResult as EpicryptAuthenticationResult;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenResult;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenInspectionStatus;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenManager;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthErrorMapper;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class OAuthTokenManager
{
    public function __construct(
        private OAuthTokenEndpoint $endpoint,
        private EpicryptOAuthClientAuthenticationAdapter $authentication,
        private RefreshTokenManager $refreshTokens,
        private ?OAuthAuditRecorder $audit = null,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function exchange(
        array $parameters,
        OAuthClientAuthentication $authentication,
        #[\SensitiveParameter]
        ?string $dpopProof = null,
    ): OAuthTokenResponse {
        $grant = OAuthGrantType::tryFrom($this->requiredString($parameters, 'grant_type', 64));
        if (!$grant instanceof OAuthGrantType) {
            throw OAuthProtocolException::unsupportedGrantType();
        }

        $result = match ($grant) {
            OAuthGrantType::AuthorizationCode => $this->authorizationCode($parameters, $authentication, $dpopProof),
            OAuthGrantType::ClientCredentials => $this->clientCredentials($parameters, $authentication, $dpopProof),
            OAuthGrantType::RefreshToken => $this->refresh($parameters, $authentication, $dpopProof),
        };

        return $this->response($result);
    }

    /** @param array<string, mixed> $parameters */
    private function authorizationCode(
        array $parameters,
        OAuthClientAuthentication $authentication,
        ?string $dpopProof,
    ): OAuthTokenResult {
        return $this->endpoint->authorizationCode(
            clientId: $authentication->clientId,
            authentication: $this->authentication->authenticate($authentication),
            code: $this->requiredString($parameters, 'code', 16_384, false),
            redirectUri: $this->requiredString($parameters, 'redirect_uri', 2_048, false),
            pkceVerifier: $this->requiredString($parameters, 'code_verifier', 128, false),
            dpopProof: $dpopProof,
        );
    }

    /** @param array<string, mixed> $parameters */
    private function clientCredentials(
        array $parameters,
        OAuthClientAuthentication $authentication,
        ?string $dpopProof,
    ): OAuthTokenResult {
        if (array_key_exists('audience', $parameters)) {
            throw OAuthProtocolException::invalidRequest(
                'Client Credentials audiences are selected by configured scope-to-resource policy.',
            );
        }

        $authenticated = $this->authentication->authenticate($authentication);
        if (!$authenticated instanceof EpicryptAuthenticationResult) {
            throw OAuthProtocolException::invalidClient();
        }

        return $this->endpoint->clientCredentials(
            authentication: $authenticated,
            requestedScopes: $this->optionalSpaceList($parameters, 'scope'),
            dpopProof: $dpopProof,
        );
    }

    /** @return list<string>|null */
    private function optionalSpaceList(array $parameters, string $name): ?array
    {
        if (!array_key_exists($name, $parameters)) {
            return null;
        }
        $value = $parameters[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 4_096) {
            throw OAuthProtocolException::invalidRequest();
        }

        $values = explode(' ', $value);
        if (in_array('', $values, true) || count($values) > 64) {
            throw OAuthProtocolException::invalidRequest();
        }

        /** @var list<string> $values */
        return $values;
    }

    /** @param array<string, mixed> $parameters */
    private function refresh(
        array $parameters,
        OAuthClientAuthentication $authentication,
        ?string $dpopProof,
    ): OAuthTokenResult {
        if (array_key_exists('audience', $parameters)) {
            throw OAuthProtocolException::invalidRequest();
        }

        $token = $this->requiredString($parameters, 'refresh_token', 16_384, false);
        $before = $this->refreshTokens->inspect($token);
        $result = $this->endpoint->refreshToken(
            clientId: $authentication->clientId,
            authentication: $this->authentication->authenticate($authentication),
            refreshToken: $token,
            requestedScopes: $this->optionalSpaceList($parameters, 'scope'),
            dpopProof: $dpopProof,
        );

        $record = $before->record;
        if ($result->successful() && $record !== null) {
            $this->audit?->record(
                AuthEventType::OAUTH_REFRESH_TOKEN_ROTATED,
                $record->grant->subject,
                [
                    'client_id' => $record->grant->clientId,
                    'authorization_id' => $record->grant->authorizationId,
                    'result' => 'rotated',
                    'scopes' => $result->response?->scopes ?? [],
                    'audiences' => $record->grant->audiences,
                ],
            );
        } elseif ($before->status === RefreshTokenInspectionStatus::CONSUMED && $record !== null) {
            $this->audit?->record(
                AuthEventType::OAUTH_REFRESH_TOKEN_REUSE,
                $record->grant->subject,
                [
                    'client_id' => $record->grant->clientId,
                    'authorization_id' => $record->grant->authorizationId,
                    'result' => 'reused',
                ],
                AuthEventSeverity::WARNING,
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $parameters */
    private function requiredString(array $parameters, string $name, int $maximumBytes, bool $trim = true): string
    {
        $value = $parameters[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $maximumBytes) {
            throw OAuthProtocolException::invalidRequest();
        }
        if (!$trim) {
            if (trim($value) !== $value) {
                throw OAuthProtocolException::invalidRequest();
            }

            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            throw OAuthProtocolException::invalidRequest();
        }

        return $value;
    }

    private function response(OAuthTokenResult $result): OAuthTokenResponse
    {
        if (!$result->successful() || $result->response === null) {
            throw EpicryptOAuthErrorMapper::exception($result->error);
        }
        $response = $result->response;

        return new OAuthTokenResponse(
            accessToken: $response->accessToken,
            expiresIn: $response->expiresIn,
            scopes: $response->scopes,
            refreshToken: $response->refreshToken,
            tokenType: $response->tokenType->value,
            additionalParameters: $response->additionalParameters,
        );
    }
}
