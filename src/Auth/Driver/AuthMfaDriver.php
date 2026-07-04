<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Driver;

enum AuthMfaDriver: string
{
    case OTP = 'otp';

    case SIMPLE = 'simple';
}
