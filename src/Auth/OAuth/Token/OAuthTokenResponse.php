<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthTokenResponse
{
    /** @param list<string> $scopes */
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public int $expiresIn,
        public array $scopes,
        #[\SensitiveParameter]
        public ?string $refreshToken = null,
        public string $tokenType = 'Bearer',
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        $response = [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'scope' => implode(' ', $this->scopes),
        ];
        if ($this->refreshToken !== null) {
            $response['refresh_token'] = $this->refreshToken;
        }

        return $response;
    }
}
