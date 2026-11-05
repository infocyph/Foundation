<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

/**
 * Application notification channel contract.
 *
 * @api
 */
interface Notification
{
    /**
     * Return channel payloads keyed by configured notification channel name.
     *
     * @return array<string,mixed>
     */
    public function channels(NotificationRecipient $recipient): array;
}
