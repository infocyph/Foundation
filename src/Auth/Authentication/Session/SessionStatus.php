<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Session;

/**
 * Public authentication session status values.
 *
 * @api
 */
enum SessionStatus: string
{
    case ACTIVE = 'active';

    case EXPIRED = 'expired';
}
