<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Policy;

interface PolicyResolverInterface
{
    public function resolve(mixed $resource): ?PolicyInterface;
}
