<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

enum MfaFactorType: string
{
    case AOTP = 'aotp';

    case CUSTOM = 'custom';

    case EMAIL = 'email';

    case GRID_OTP = 'grid_otp';

    case HOTP = 'hotp';

    case MOBILE_OTP = 'mobile_otp';

    case OCRA = 'ocra';

    case PASSKEY = 'passkey';

    case RECOVERY_CODE = 'recovery_code';

    case SMS = 'sms';

    case TOTP = 'totp';
}
