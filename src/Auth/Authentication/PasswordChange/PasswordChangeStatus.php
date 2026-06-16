<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\PasswordChange;

enum PasswordChangeStatus: string
{
    case ACCOUNT_NOT_FOUND = 'account_not_found';

    case CHANGED = 'changed';

    case INVALID_CREDENTIALS = 'invalid_credentials';

    case POLICY_FAILED = 'policy_failed';
}
