<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Internal\AuthMfaRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\OTP\TOTP;

final class AuthOtpServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (!class_exists(TOTP::class)) {
            throw new \LogicException(
                'Foundation OTP services require infocyph/otp; run "php infbyte module:install otp".',
            );
        }

        $app = $this->application($builder, $context);
        $issues = new OtpConfigValidator($app->config())->validate();
        if ($issues !== []) {
            throw new ConfigurationException(
                'Invalid Foundation OTP configuration: ' . implode(
                    '; ',
                    array_map(static fn($issue): string => $issue->message, $issues),
                ),
            );
        }

        new AuthMfaRegistrar(
            $app,
            $builder,
            new AuthSecretResolver($app),
        )->registerOtpSupport();
    }
}
