<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\TalkingBytes;

use Infocyph\AuthLayer\Contract\Notification\AuthNotifierInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Notification\AuthNotification;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

final readonly class TalkingBytesAuthNotifier implements AuthNotifierInterface
{
    public function __construct(
        private Emailer $emailer,
        private AuthNotificationMapper $mapper,
        private AccountProviderInterface $accounts,
        private ?string $from = null,
    ) {}

    public function send(AuthNotification $notification): void
    {
        $recipient = $this->resolveRecipient($notification);
        if ($recipient === null) {
            return;
        }

        $result = $this->emailer->send(
            $this->mapper->toEmail($notification, $recipient, $this->from),
        );

        if (!$result->successful) {
            throw new \RuntimeException($result->error ?? 'Failed to send auth notification.');
        }
    }

    private function resolveRecipient(AuthNotification $notification): ?string
    {
        $candidates = [
            $notification->payload['email'] ?? null,
            $notification->payload['identifier'] ?? null,
            $notification->payload['account_email'] ?? null,
            $notification->payload['recipient_email'] ?? null,
        ];

        if ($notification->accountId !== null) {
            $account = $this->accounts->findById($notification->accountId);
            if ($account !== null) {
                $candidates[] = $account->metadata()['email'] ?? null;
                $candidates[] = $account->identifier();
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                return EmailAddress::fromMailbox($candidate)->email;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
