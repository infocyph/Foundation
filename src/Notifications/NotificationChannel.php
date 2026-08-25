<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

interface NotificationChannel
{
    public function send(
        NotificationRecipient $recipient,
        Notification $notification,
        mixed $payload,
    ): mixed;
}
