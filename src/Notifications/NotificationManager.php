<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Support\AbstractContainerManager;

final readonly class NotificationManager extends AbstractContainerManager
{
    public function authNotifier(): AuthNotifierInterface
    {
        return $this->typedService(
            AuthNotifierInterface::class,
            'Notification auth notifier must resolve to AuthNotifierInterface.',
        );
    }

    public function emailer(): object
    {
        return $this->objectService(
            'foundation.notifications.emailer',
            'Foundation notification emailer must resolve to an object.',
        );
    }

    protected function configSection(): string
    {
        return 'notifications';
    }
}
