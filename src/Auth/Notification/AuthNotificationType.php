<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Notification;

enum AuthNotificationType: string
{
    case ACCOUNT_LOCKED = 'account_locked';

    case DELEGATED_ACCESS_GRANTED = 'delegated_access_granted';

    case DELEGATED_ACCESS_REVOKED = 'delegated_access_revoked';

    case EMAIL_VERIFICATION_REQUESTED = 'email_verification_requested';

    case LOGIN_ALERT = 'login_alert';

    case MFA_CHALLENGE_REQUESTED = 'mfa_challenge_requested';

    case NEW_DEVICE_ALERT = 'new_device_alert';

    case PASSKEY_REGISTERED = 'passkey_registered';

    case PASSKEY_REMOVED = 'passkey_removed';

    case PASSWORD_CHANGED = 'password_changed';

    case PASSWORD_RESET_REQUESTED = 'password_reset_requested';

    case PASSWORDLESS_LOGIN_REQUESTED = 'passwordless_login_requested';

    case SUSPICIOUS_ACTIVITY = 'suspicious_activity';
}
