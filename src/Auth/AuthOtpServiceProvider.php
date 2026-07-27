<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Internal\AuthMfaRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\OTP\TOTP;

final class AuthOtpServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if ($app->container()->has(OtpProvisioningService::class)) {
            return;
        }
        if (!class_exists(TOTP::class)) {
            throw new \LogicException(
                'Foundation OTP services require infocyph/otp; run "php infbyte module:install otp".',
            );
        }

        new AuthMfaRegistrar(
            $app,
            $app->container(),
            new AuthSecretResolver($app),
        )->registerOtpSupport();
    }
}
