<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;

final class CollectingAuthNotifier implements AuthNotifierInterface
{
    /**
     * @var list<AuthNotification>
     */
    private array $notifications = [];

    public function flush(): void
    {
        $this->notifications = [];
    }

    /**
     * @return list<AuthNotification>
     */
    public function notifications(): array
    {
        return $this->notifications;
    }

    public function send(AuthNotification $notification): void
    {
        $this->notifications[] = $notification;
    }
}
