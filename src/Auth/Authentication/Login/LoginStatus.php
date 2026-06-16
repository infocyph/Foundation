<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Login;

enum LoginStatus: string
{
    case ACCOUNT_DISABLED = 'account_disabled';

    case ACCOUNT_LOCKED = 'account_locked';

    case AUTHENTICATED = 'authenticated';

    case EMAIL_VERIFICATION_REQUIRED = 'email_verification_required';

    case INVALID_CREDENTIALS = 'invalid_credentials';

    case MFA_REQUIRED = 'mfa_required';

    case PASSKEY_REQUIRED = 'passkey_required';

    case PASSWORD_CHANGE_REQUIRED = 'password_change_required';

    case STEP_UP_REQUIRED = 'step_up_required';
}
