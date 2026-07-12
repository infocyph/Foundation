<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

enum MfaFactorType: string
{
    case CUSTOM = 'custom';

    case EMAIL = 'email';

    case HOTP = 'hotp';

    case OCRA = 'ocra';

    case PASSKEY = 'passkey';

    case RECOVERY_CODE = 'recovery_code';

    case SMS = 'sms';

    case TOTP = 'totp';
}
