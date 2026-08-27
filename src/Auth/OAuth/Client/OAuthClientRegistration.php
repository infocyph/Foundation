<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Client;

final readonly class OAuthClientRegistration
{
    public function __construct(
        public OAuthClient $client,
        #[\SensitiveParameter]
        public ?string $secret,
    ) {}
}
