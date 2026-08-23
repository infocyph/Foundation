<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

final readonly class NotificationDispatcher
{
    public function __construct(private NotificationChannelRegistry $channels) {}

    /** @return array<string,mixed> */
    public function send(NotificationRecipient $recipient, Notification $notification): array
    {
        $payloads = $notification->channels($recipient);
        if (!is_array($payloads)) {
            throw new \UnexpectedValueException('Notification channels must return a channel payload map.');
        }

        $results = [];
        foreach ($payloads as $channel => $payload) {
            if (!is_string($channel) || trim($channel) === '') {
                throw new \InvalidArgumentException('Notification channel keys must be non-empty strings.');
            }

            $results[$channel] = $this->channels
                ->channel($channel)
                ->send($recipient, $notification, $payload);
        }

        return $results;
    }
}
