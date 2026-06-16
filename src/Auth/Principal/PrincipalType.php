<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Principal;

enum PrincipalType: string
{
    case ACCOUNT = 'account';

    case GUEST = 'guest';

    case IMPERSONATED = 'impersonated';

    case SERVICE = 'service';
}
