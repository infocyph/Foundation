<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Lockout;

enum LockoutStatus: string
{
    case CLEAR = 'clear';

    case FAILURE_RECORDED = 'failure_recorded';

    case LOCKED = 'locked';

    case UNLOCKED = 'unlocked';
}
