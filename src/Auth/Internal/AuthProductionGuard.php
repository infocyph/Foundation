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

        if ($drivers->notifications() === AuthNotificationDriver::TALKINGBYTES) {
            $this->guardTalkingBytesNotifications();
        }

        if ($drivers->passkey() === AuthPasskeyDriver::MEMORY) {
            throw new ConfigurationException('auth.drivers.passkey must not be "memory" in production.');
        }
    }

    private function guardTalkingBytesNotifications(): void
    {
        $sender = $this->app->config()->get('notifications.auth.sender', 'auth');
        if (!is_string($sender) || trim($sender) === '') {
            throw new ConfigurationException('notifications.auth.sender must select an email sender profile.');
        }
        $sender = trim($sender);
        $profile = $this->app->config()->get('notifications.email.senders.' . $sender);
        if (!is_array($profile)) {
            throw new ConfigurationException(sprintf(
                'Auth notification email sender profile "%s" is not configured.',
                $sender,
            ));
        }

        $transport = $profile['transport'] ?? null;
        if (!is_string($transport) || trim($transport) === '') {
            throw new ConfigurationException(sprintf(
                'Auth notification email sender "%s" must select a transport.',
                $sender,
            ));
        }
        $transport = trim($transport);
        $transportConfig = $this->app->config()->get('notifications.email.transports.' . $transport);
        if (!is_array($transportConfig)) {
            throw new ConfigurationException(sprintf(
                'Auth notification email transport "%s" is not configured.',
                $transport,
            ));
        }

        $driver = $transportConfig['driver'] ?? $transport;
        if (!is_string($driver) || in_array(strtolower(trim($driver)), ['null', 'fake'], true)) {
            throw new ConfigurationException(
                'Authentication notifications must deliver or deliberately log email in production.',
            );
        }
    }
}
