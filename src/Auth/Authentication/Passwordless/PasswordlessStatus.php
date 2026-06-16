<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

enum PasswordlessStatus: string
{
    case EXPIRED = 'expired';

    case INVALID = 'invalid';

    case ISSUED = 'issued';

    case VERIFIED = 'verified';
}
