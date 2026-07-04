<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Support\ValueNormalizer;

trait NormalizesTokenClaims
{
    /**
     * @param array<mixed> $claims
     * @return array<string, mixed>
     */
    protected function normalizeClaims(array $claims): array
    {
        return ValueNormalizer::associativeArray($claims);
    }
}
