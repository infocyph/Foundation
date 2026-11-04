<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\TalkingBytes;

use Infocyph\Foundation\Auth\Account\AccountInterface;
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

    #[\Override]
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

    private function accountRecipient(AuthNotification $notification): ?string
    {
        $account = $notification->accountId === null ? null : $this->accounts->findById($notification->accountId);
        if ($account === null) {
            if ($notification->type === AuthNotificationType::PASSWORD_RESET_REQUESTED) {
                throw new \RuntimeException('Password reset requires an existing account recipient.');
            }

            return null;
        }

        $recipient = $this->storedAddress($account);
        if ($recipient === null && $notification->type === AuthNotificationType::PASSWORD_RESET_REQUESTED) {
            throw new \RuntimeException('Password reset account has no valid trusted email recipient.');
        }

        return $recipient;
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
     * Account-linked notifications resolve the recipient from authoritative account
     * state; only verification uses its validated, purpose-specific new address.
     */
    private function passwordlessRecipient(AuthNotification $notification): ?string
    {
        $identifier = $notification->payload['identifier'] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            return null;
        }

        $account = $this->accounts->findByIdentifier($identifier);

        return $account === null ? null : $this->storedAddress($account);
    }

    private function resolveRecipient(AuthNotification $notification): ?string
    {
        return match ($notification->type) {
            AuthNotificationType::EMAIL_VERIFICATION_REQUESTED => $this->verificationRecipient($notification),
            AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED => $this->passwordlessRecipient($notification),
            default => $this->accountRecipient($notification),
        };
    }

    private function shouldThrow(AuthNotification $notification): bool
    {
        if ($this->failSilently) {
            return false;
        }

        return in_array($notification->type->value, $this->criticalTypes, true)
            || $this->isCriticalByDefault($notification->type);
    }

    private function storedAddress(AccountInterface $account): ?string
    {
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

        return null;
    }

    private function verificationRecipient(AuthNotification $notification): string
    {
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
}
