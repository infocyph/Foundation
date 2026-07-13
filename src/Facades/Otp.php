<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Otp\OtpManager;

/** @mixin OtpManager */
final class Otp extends ManagerFacade
{
    public static function manager(): OtpManager
    {
        return self::app()->otp();
    }
}
