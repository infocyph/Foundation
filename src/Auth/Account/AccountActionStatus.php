<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

enum AccountActionStatus: string
{
    case ALREADY_EXISTS = 'already_exists';

    case CREATED = 'created';

    case NOT_FOUND = 'not_found';

    case UPDATED = 'updated';
}
