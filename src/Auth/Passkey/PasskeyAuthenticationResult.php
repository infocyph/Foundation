<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyAuthenticationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $challengeId,
        public string $credentialId,
        public string $clientData,
        public string $authenticatorData,
        public string $signature,
        public ?string $userHandle = null,
        public array $metadata = [],
    ) {}
}
