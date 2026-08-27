<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Contract;

interface JwkSetProviderInterface
{
    /** @return array{keys:list<array<string, mixed>>} */
    public function jwks(): array;
}
