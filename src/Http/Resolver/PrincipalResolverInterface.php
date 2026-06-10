<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Http\Resolver;

use Infocyph\AuthLayer\Principal\PrincipalInterface;
use Infocyph\Webrick\Request\Request;

interface PrincipalResolverInterface
{
    public function name(): string;

    public function resolve(Request $request): ?PrincipalInterface;
}
