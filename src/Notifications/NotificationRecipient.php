<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

/**
 * Application-facing notification recipient contract.
 *
 * @api
 */
interface NotificationRecipient
{
    public function routeNotificationFor(string $channel, Notification $notification): mixed;
}
