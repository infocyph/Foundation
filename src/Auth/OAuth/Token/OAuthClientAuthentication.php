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
    ) {
        if ($this->clientId === '' || strlen($this->clientId) > 128) {
            throw new \InvalidArgumentException('OAuth client authentication is invalid.');
        }
        if ($this->method === OAuthClientAuthenticationMethod::None && $this->secret !== null) {
            throw new \InvalidArgumentException('Public OAuth client authentication must not contain a secret.');
        }
        if ($this->method === OAuthClientAuthenticationMethod::ClientSecretBasic && ($this->secret === null || $this->secret === '')) {
            throw new \InvalidArgumentException('Confidential OAuth client authentication requires a secret.');
        }
    }
}
