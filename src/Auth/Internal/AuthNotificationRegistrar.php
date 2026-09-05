<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthNotificationDriver;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Notifications\EmailProfiles;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\TalkingBytes\Email\Emailer;

final readonly class AuthNotificationRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->notifications() === AuthNotificationDriver::TALKINGBYTES) {
            $this->requirePackage(Emailer::class, 'infocyph/talkingbytes', 'communication');
            $this->recipe(AuthNotificationMapper::class, AuthNotificationMapper::class, [
                $this->ref(NotificationTemplateRegistry::class),
            ]);
            $this->staticRecipe(
                AuthNotifierInterface::class,
                AuthNotificationGraphFactory::class,
                'talkingBytes',
                [
                    $this->ref(EmailProfiles::class),
                    $this->ref(AuthNotificationMapper::class),
                    $this->ref(AccountProviderInterface::class),
                    $this->criticalTypes(),
                    $this->boolConfig('notifications.auth.fail_silently', false),
                    $this->notificationFrom(),
                ],
                LifetimeEnum::Scoped,
            );

            return;
        }

        $this->recipe(
            AuthNotifierInterface::class,
            CollectingAuthNotifier::class,
            lifetime: LifetimeEnum::Scoped,
        );
    }

    /** @return list<string> */
    private function criticalTypes(): array
    {
        return $this->stringList($this->app->config()->get('notifications.auth.critical_types', []));
    }

    private function notificationFrom(): ?string
    {
        return $this->nullableString($this->app->config()->get('notifications.auth.from'))
            ?? $this->nullableString($this->app->config()->get('notifications.email.default_from'));
    }
}
