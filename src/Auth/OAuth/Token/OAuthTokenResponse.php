<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthTokenResponse
{
    /**
     * @param list<string> $scopes
     * @param array<string,string> $additionalParameters
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public int $expiresIn,
        public array $scopes,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
        public string $tokenType = 'Bearer',
        #[\SensitiveParameter]
        public array $additionalParameters = [],
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'scope' => implode(' ', $this->scopes),
            ...($this->refreshToken === null ? [] : ['refresh_token' => $this->refreshToken]),
            ...$this->additionalParameters,
        ];
    }
}
