<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenInspector;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAccessTokenService;
use Infocyph\Epicrypt\Auth\OAuth\OAuthRevocationEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenTypeHint;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenManager;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthErrorMapper;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Audit\OAuthAuditRecorder;

final readonly class OAuthRevocationManager
{
    private OAuthAccessTokenInspector $accessInspector;

    public function __construct(
        private OAuthRevocationEndpoint $endpoint,
        private EpicryptOAuthClientAuthenticationAdapter $authentication,
        OAuthAccessTokenService $accessTokens,
        private RefreshTokenManager $refreshTokens,
        private ?OAuthAuditRecorder $audit = null,
    ) {
        $this->accessInspector = new OAuthAccessTokenInspector($accessTokens);
    }

    public function revoke(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $access = $tokenTypeHint === 'refresh_token' ? null : $this->accessInspector->inspect($token);
        $refresh = $tokenTypeHint === 'access_token' ? null : $this->refreshTokens->inspect($token);

        $hint = match ($tokenTypeHint) {
            'access_token' => OAuthTokenTypeHint::ACCESS_TOKEN,
            'refresh_token' => OAuthTokenTypeHint::REFRESH_TOKEN,
            default => null,
        };
        $result = $this->endpoint->revoke(
            clientId: $authentication->clientId,
            authentication: $this->authentication->authenticate($authentication),
            token: $token,
            hint: $hint,
        );
        if (!$result->accepted) {
            throw EpicryptOAuthErrorMapper::exception(
                $result->error === null
                    ? null
                    : new \Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError($result->error),
            );
        }

        if ($access?->valid() === true
            && is_string($access->claims['client_id'] ?? null)
            && hash_equals($authentication->clientId, $access->claims['client_id'])
        ) {
            $this->audit?->record(AuthEventType::OAUTH_ACCESS_TOKEN_REVOKED, metadata: [
                'client_id' => $authentication->clientId,
                'authorization_id' => self::string($access->claims['authorization_id'] ?? null),
                'token_type' => 'access_token',
                'result' => 'revoked',
            ]);

            return;
        }

        $record = $refresh?->record;
        if ($record !== null && hash_equals($authentication->clientId, $record->grant->clientId)) {
            $this->audit?->record(
                AuthEventType::OAUTH_REFRESH_TOKEN_REVOKED,
                $record->grant->subject,
                [
                    'client_id' => $record->grant->clientId,
                    'authorization_id' => $record->grant->authorizationId,
                    'result' => 'revoked',
                ],
            );
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
