<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Exception;

final class OAuthProtocolException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = 400,
        public readonly bool $redirectAllowed = false,
    ) {
        parent::__construct($error);
    }

    public static function accessDenied(): self
    {
        return new self('access_denied', 'The resource owner denied the authorization request.', 400, true);
    }

    public static function invalidClient(): self
    {
        return new self('invalid_client', 'Client authentication failed.', 401);
    }

    public static function invalidGrant(): self
    {
        return new self('invalid_grant', 'The supplied grant is invalid or no longer usable.');
    }

    public static function invalidRequest(string $description = 'The OAuth request is invalid.', bool $redirectAllowed = false): self
    {
        return new self('invalid_request', $description, 400, $redirectAllowed);
    }

    public static function invalidScope(bool $redirectAllowed = false): self
    {
        return new self('invalid_scope', 'The requested scope is invalid.', 400, $redirectAllowed);
    }

    public static function unauthorizedClient(bool $redirectAllowed = false): self
    {
        return new self('unauthorized_client', 'The client is not allowed to use this request.', 400, $redirectAllowed);
    }

    public static function unsupportedGrantType(): self
    {
        return new self('unsupported_grant_type', 'The requested grant type is not supported.');
    }

    public static function unsupportedResponseType(bool $redirectAllowed = false): self
    {
        return new self('unsupported_response_type', 'The requested response type is not supported.', 400, $redirectAllowed);
    }
}
