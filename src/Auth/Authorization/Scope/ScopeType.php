<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Scope;

enum ScopeType: string
{
    case ORGANIZATION = 'organization';

    case TENANT = 'tenant';

    case WORKSPACE = 'workspace';
}
