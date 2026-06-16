<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

enum LockoutReason: string
{
    case ABUSE_DETECTED = 'abuse_detected';

    case ADMINISTRATIVE = 'administrative';

    case TOO_MANY_LOGIN_ATTEMPTS = 'too_many_login_attempts';

    case TOO_MANY_MFA_FAILURES = 'too_many_mfa_failures';

    case TOO_MANY_PASSKEY_FAILURES = 'too_many_passkey_failures';
}
