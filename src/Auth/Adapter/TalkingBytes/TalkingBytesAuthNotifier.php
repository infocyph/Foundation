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

    /**
     * Security notifications resolve the recipient from authoritative account
     * state; only verification uses its validated, purpose-specific new address.
     */
    private function resolveRecipient(AuthNotification $notification): ?string
    {
        if ($notification->type === AuthNotificationType::EMAIL_VERIFICATION_REQUESTED) {
            $email = $notification->payload['email'] ?? null;
            if (!is_string($email) || $email === '') {
                throw new \RuntimeException('Email verification requires an explicit destination.');
            }

            try {
                return EmailAddress::fromMailbox($email)->email;
            } catch (\InvalidArgumentException $exception) {
                throw new \RuntimeException('Email verification destination is invalid.', 0, $exception);
            }
        }

        if ($notification->type === AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED) {
            $identifier = $notification->payload['identifier'] ?? null;
            $account = is_string($identifier) && $identifier !== ''
                ? $this->accounts->findByIdentifier($identifier)
                : null;
        } else {
            $account = $notification->accountId === null
                ? null
                : $this->accounts->findById($notification->accountId);
        }

        if ($account === null) {
            if ($notification->type === AuthNotificationType::PASSWORD_RESET_REQUESTED) {
                throw new \RuntimeException('Password reset requires an existing account recipient.');
            }

            return null;
        }

        foreach ([$account->identifier(), $account->metadata()['email'] ?? null] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                return EmailAddress::fromMailbox($candidate)->email;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        if ($notification->type === AuthNotificationType::PASSWORD_RESET_REQUESTED) {
            throw new \RuntimeException('Password reset account has no valid trusted email recipient.');
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
