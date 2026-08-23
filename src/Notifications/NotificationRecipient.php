<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

interface NotificationRecipient
{
    public function routeNotificationFor(string $channel): mixed;
}
