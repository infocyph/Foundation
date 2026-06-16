<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Session;

enum SessionStatus: string
{
    case ACTIVE = 'active';

    case EXPIRED = 'expired';
}
