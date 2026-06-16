<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

final readonly class WebAuthnRegistrationPayload
{
    /**
     * @param list<string> $transports
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $challengeId,
        public string $credentialId,
        public string $publicKey,
        public array $transports = [],
        public int $signCount = 0,
        public array $metadata = [],
    ) {}
}
