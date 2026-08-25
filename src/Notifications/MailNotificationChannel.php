<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class MailNotificationChannel implements NotificationChannel
{
    public function __construct(private Mailer $mailer) {}

    public function send(
        NotificationRecipient $recipient,
        Notification $notification,
        mixed $payload,
    ): mixed {
        $profile = null;
        if ($payload instanceof MailMessage) {
            $profile = $payload->senderProfile();
            $message = $payload->toEmail();
        } elseif ($payload instanceof EmailMessage) {
            $message = $payload;
        } else {
            throw new \InvalidArgumentException(sprintf(
                'Mail notification payload must be %s or %s.',
                MailMessage::class,
                EmailMessage::class,
            ));
        }

        $recipients = $this->recipients($recipient->routeNotificationFor('mail', $notification));
        if ($recipients === []) {
            throw new \LogicException('Mail notification recipient has no mail route.');
        }

        return $this->mailer->sendEmail($message->withTo(...$recipients), $profile);
    }

    /** @return list<string> */
    private function recipients(mixed $route): array
    {
        if (is_string($route)) {
            $route = [$route];
        }
        if (!is_array($route)) {
            return [];
        }

        $recipients = [];
        foreach ($route as $address) {
            if (!is_string($address) || trim($address) === '') {
                throw new \InvalidArgumentException('Mail notification routes must be non-empty mailbox strings.');
            }
            $recipients[] = trim($address);
        }

        return array_values(array_unique($recipients));
    }
}
