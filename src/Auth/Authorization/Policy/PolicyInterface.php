<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Policy;

use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

interface PolicyInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function authorize(
        PrincipalInterface $principal,
        string $ability,
        mixed $resource = null,
        array $context = [],
    ): AuthorizationDecision|bool|null;
}
