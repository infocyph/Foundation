<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;

final readonly class AuthNotificationRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->notifications() === AuthNotificationDriver::TALKINGBYTES) {
            $this->singleton(AuthNotificationMapper::class, fn() => new AuthNotificationMapper(
                $this->app->make(NotificationTemplateRegistry::class),
            ));

            $this->singleton(AuthNotifierInterface::class, fn() => new TalkingBytesAuthNotifier(
                emailer: $this->app->notifications()->emailer(),
                mapper: $this->app->make(AuthNotificationMapper::class),
                accounts: $this->app->make(AccountProviderInterface::class),
                criticalTypes: $this->criticalTypes(),
                failSilently: $this->boolConfig('notifications.auth.fail_silently', false),
                from: $this->nullableString($this->app->config()->get('notifications.auth.from')),
            ));

            return;
        }

        $this->singleton(AuthNotifierInterface::class, fn() => new CollectingAuthNotifier());
    }

    /**
     * @return list<string>
     */
    private function criticalTypes(): array
    {
        return $this->stringList($this->app->config()->get('notifications.auth.critical_types', []));
    }
}
