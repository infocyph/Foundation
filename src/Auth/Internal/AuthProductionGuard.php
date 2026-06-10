<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthMfaDriver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Driver\AuthPasskeyDriver;
use Infocyph\Foundation\Auth\Driver\AuthStorageDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class AuthProductionGuard
{
    public function __construct(
        private Application $app,
    ) {}

    public function guard(AuthDriverResolver $drivers): void
    {
        if (!$this->app->config()->isProduction()) {
            return;
        }

        if ($drivers->tokens() === AuthTokenDriver::SIMPLE) {
            throw new ConfigurationException('auth.drivers.tokens must not be "simple" in production.');
        }

        if ($drivers->storage() === AuthStorageDriver::MEMORY) {
            throw new ConfigurationException('auth.drivers.storage must not be "memory" in production.');
        }

        if ($drivers->mfa() === AuthMfaDriver::SIMPLE) {
            throw new ConfigurationException('auth.drivers.mfa must not be "simple" in production.');
        }

        if ($drivers->notifications() === AuthNotificationDriver::COLLECT) {
            throw new ConfigurationException('auth.drivers.notifications must not be "collect" in production.');
        }

        if ($drivers->passkey() === AuthPasskeyDriver::MEMORY) {
            throw new ConfigurationException('auth.drivers.passkey must not be "memory" in production.');
        }
    }
}
