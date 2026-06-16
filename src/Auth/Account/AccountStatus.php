<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Account;

enum AccountStatus: string
{
    case ACTIVE = 'active';

    case DISABLED = 'disabled';

    case LOCKED = 'locked';

    case MFA_ENROLLMENT_REQUIRED = 'mfa_enrollment_required';

    case PASSWORD_CHANGE_REQUIRED = 'password_change_required';

    case PENDING_VERIFICATION = 'pending_verification';

    case SUSPENDED = 'suspended';
}
