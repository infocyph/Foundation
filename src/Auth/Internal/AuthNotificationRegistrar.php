<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\AuthLayer\Contract\Notification\AuthNotifierInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthNotificationRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->notifications() === AuthNotificationDriver::TALKINGBYTES) {
            $this->container->bind(AuthNotificationMapper::class, fn() => new AuthNotificationMapper(
                $this->container->get(NotificationTemplateRegistry::class),
            ), LifetimeEnum::Singleton);

            $this->container->bind(AuthNotifierInterface::class, fn() => new TalkingBytesAuthNotifier(
                emailer: $this->container->get('foundation.notifications.emailer'),
                mapper: $this->container->get(AuthNotificationMapper::class),
                accounts: $this->container->get(AccountProviderInterface::class),
                from: $this->optionalString($this->app->config()->get('notifications.auth.from')),
            ), LifetimeEnum::Singleton);

            return;
        }

        if ($drivers->notifications() !== AuthNotificationDriver::COLLECT) {
            throw new ConfigurationException(sprintf(
                'Auth notification driver "%s" is not implemented yet.',
                $drivers->notifications()->value,
            ));
        }

        $this->container->bind(AuthNotifierInterface::class, fn() => new CollectingAuthNotifier(), LifetimeEnum::Singleton);
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
