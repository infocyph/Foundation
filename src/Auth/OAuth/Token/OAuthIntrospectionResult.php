<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

final readonly class OAuthIntrospectionResult
{
    /**
     * @param list<string> $audiences
     * @param list<string> $scopes
     */
    public function __construct(
        public bool $active,
        public ?string $clientId = null,
        public ?string $subject = null,
        public array $audiences = [],
        public array $scopes = [],
        public ?int $expiresAt = null,
        public ?int $issuedAt = null,
        public ?string $tokenId = null,
        public ?string $tokenType = null,
    ) {}

    public static function inactive(): self
    {
        return new self(false);
    }

    /** @return array<string, bool|int|string|list<string>> */
    public function toArray(): array
    {
        if (!$this->active) {
            return ['active' => false];
        }

        $result = [
            'active' => true,
            'client_id' => $this->clientId ?? '',
            'scope' => implode(' ', $this->scopes),
            'token_type' => $this->tokenType ?? 'Bearer',
        ];
        if ($this->subject !== null) {
            $result['sub'] = $this->subject;
        }
        if ($this->audiences !== []) {
            $result['aud'] = $this->audiences;
        }
        if ($this->expiresAt !== null) {
            $result['exp'] = $this->expiresAt;
        }
        if ($this->issuedAt !== null) {
            $result['iat'] = $this->issuedAt;
        }
        if ($this->tokenId !== null) {
            $result['jti'] = $this->tokenId;
        }

        return $result;
    }
}
