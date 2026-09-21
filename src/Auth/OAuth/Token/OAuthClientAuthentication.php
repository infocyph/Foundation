<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;

final readonly class OAuthClientAuthentication
{
    public function __construct(
        public OAuthClientAuthenticationMethod $method,
        public string $clientId,
        #[\SensitiveParameter]
        public ?string $secret = null,
        #[\SensitiveParameter]
        public ?string $assertion = null,
    ) {
        if ($this->clientId === '' || strlen($this->clientId) > 128) {
            throw new \InvalidArgumentException('OAuth client authentication is invalid.');
        }

        match ($this->method) {
            OAuthClientAuthenticationMethod::None => $this->assertNone(),
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            OAuthClientAuthenticationMethod::ClientSecretPost => $this->assertSecret(),
            OAuthClientAuthenticationMethod::PrivateKeyJwt => $this->assertAssertion(),
        };
    }

    private function assertAssertion(): void
    {
        if ($this->secret !== null
            || !is_string($this->assertion)
            || $this->assertion === ''
            || strlen($this->assertion) > 16_384
        ) {
            throw new \InvalidArgumentException('private_key_jwt authentication requires exactly one bounded assertion.');
        }
    }

    private function assertNone(): void
    {
        if ($this->secret !== null || $this->assertion !== null) {
            throw new \InvalidArgumentException('Public OAuth client authentication must not contain credentials.');
        }
    }

    private function assertSecret(): void
    {
        if (!is_string($this->secret)
            || $this->secret === ''
            || strlen($this->secret) > 4_096
            || $this->assertion !== null
        ) {
            throw new \InvalidArgumentException('OAuth client-secret authentication requires exactly one bounded secret.');
        }
    }
}
