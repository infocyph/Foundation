<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Internal\AuthMfaRegistrar;
use Infocyph\Foundation\Config\ConfigRepository;
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
                'Foundation OTP services require infocyph/otp; run "php infbyte module:install security.mfa".',
            );
        }

        $config = new ConfigRepository($context->config, $context->compiledConfig);
        $issues = new OtpConfigValidator($config)->validate();
        if ($issues !== []) {
            throw new ConfigurationException(
                'Invalid Foundation OTP configuration: ' . implode(
                    '; ',
                    array_map(static fn($issue): string => $issue->message, $issues),
                ),
            );
        }

        if ($context->permitsCapability('auth')
            && new AuthDriverResolver($config)->mfa() === AuthMfaDriver::OTP
        ) {
            return;
        }

        new AuthMfaRegistrar($config, $builder)->registerOtpSupport();
    }
}
