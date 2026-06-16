<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyRegistrationResult
{
    /**
     * @param list<string> $transports
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $challengeId,
        public string $accountId,
        public string $credentialId,
        public string $publicKey,
        public array $transports = [],
        public int $signCount = 0,
        public array $metadata = [],
    ) {}
}
