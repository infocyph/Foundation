<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Mfa\MfaFactor;

final readonly class MfaSecretProtectionResult
{
    public function __construct(
        public MfaFactor $factor,
        public bool $usedFallbackKey = false,
        public bool $legacyPlaintext = false,
    ) {}
}
