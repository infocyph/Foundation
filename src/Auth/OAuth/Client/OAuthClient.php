<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Client;

use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;

final readonly class OAuthClient
{
    /**
     * @param list<OAuthGrantType> $grants
     * @param list<string> $audiences
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public OAuthClientType $type,
        public OAuthClientAuthenticationMethod $authenticationMethod,
        #[\SensitiveParameter]
        public ?string $secretHash,
        public array $grants,
        public array $audiences,
        public bool $enabled,
        public int $createdAt,
        public int $updatedAt,
        public ?int $disabledAt = null,
        public array $metadata = [],
    ) {}

    public function allowsAudience(string $audience): bool
    {
        return in_array($audience, $this->audiences, true);
    }

    public function allowsGrant(OAuthGrantType $grant): bool
    {
        return array_any($this->grants, fn($allowed) => $allowed === $grant);
    }

    public function confidential(): bool
    {
        return $this->type === OAuthClientType::Confidential;
    }

    public function public(): bool
    {
        return $this->type === OAuthClientType::Public;
    }
}
