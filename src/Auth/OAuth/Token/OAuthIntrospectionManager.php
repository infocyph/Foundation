<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Auth\OAuth\OAuthClientAuthenticationResult as EpicryptAuthenticationResult;
use Infocyph\Epicrypt\Auth\OAuth\OAuthIntrospectionEndpoint;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthErrorMapper;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;

final readonly class OAuthIntrospectionManager
{
    public function __construct(
        private OAuthIntrospectionEndpoint $endpoint,
        private EpicryptOAuthClientAuthenticationAdapter $authentication,
    ) {}

    public function introspect(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
    ): OAuthIntrospectionResult {
        $authenticated = $this->authentication->authenticate($authentication);
        if (!$authenticated instanceof EpicryptAuthenticationResult) {
            throw OAuthProtocolException::invalidClient();
        }

        $result = $this->endpoint->introspect($authenticated, $token);
        if ($result->error !== null) {
            throw EpicryptOAuthErrorMapper::exception(
                new \Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError($result->error),
            );
        }
        $response = $result->response;
        if ($response === null || !$response->active) {
            return OAuthIntrospectionResult::inactive();
        }

        $metadata = $response->metadata;

        return new OAuthIntrospectionResult(
            active: true,
            clientId: self::string($metadata['client_id'] ?? null),
            subject: self::string($metadata['sub'] ?? null),
            audiences: self::stringList($metadata['aud'] ?? null),
            scopes: self::scopeList($metadata['scope'] ?? null),
            expiresAt: self::integer($metadata['exp'] ?? null),
            issuedAt: self::integer($metadata['iat'] ?? null),
            tokenId: self::string($metadata['jti'] ?? null),
            tokenType: self::string($metadata['token_type'] ?? null),
        );
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** @return list<string> */
    private static function scopeList(mixed $value): array
    {
        return is_string($value) && $value !== '' ? explode(' ', $value) : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
