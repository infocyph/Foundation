<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\TalkingBytes;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

final readonly class TalkingBytesAuthNotifier implements AuthNotifierInterface
{
    /**
     * @param list<string> $criticalTypes
     */
    public function __construct(
        private Emailer $emailer,
        private AuthNotificationMapper $mapper,
        private AccountProviderInterface $accounts,
        private array $criticalTypes = [],
        private bool $failSilently = false,
        private ?string $from = null,
    ) {}

    public function send(AuthNotification $notification): void
    {
        $recipient = $this->resolveRecipient($notification);
        if ($recipient === null) {
            return;
        }

        $result = $this->emailer->send($this->mapper->toEmail($notification, $recipient, $this->from));

        if (!$result->successful) {
            $message = $result->error ?? 'Failed to send auth notification.';

            if ($this->shouldThrow($notification)) {
                throw new \RuntimeException($message);
            }

            error_log(sprintf(
                '[foundation.auth.notification] %s (%s)',
                $message,
                $notification->type->value,
            ));
        }
    }

    private function isCriticalByDefault(AuthNotificationType $type): bool
    {
        return in_array($type, [
            AuthNotificationType::PASSWORD_RESET_REQUESTED,
            AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
            AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED,
            AuthNotificationType::MFA_CHALLENGE_REQUESTED,
        ], true);
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

    private function shouldThrow(AuthNotification $notification): bool
    {
        if ($this->failSilently) {
            return false;
        }

        return in_array($notification->type->value, $this->criticalTypes, true)
            || $this->isCriticalByDefault($notification->type);
    }
}
