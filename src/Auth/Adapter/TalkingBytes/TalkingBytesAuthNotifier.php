<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\TalkingBytes;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;

final readonly class TalkingBytesAuthNotifier implements AuthNotifierInterface
{
    /**
     * @param list<string> $criticalTypes
     */
    public function __construct(
        private object $emailer,
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

        $result = $this->invoke(
            $this->emailer,
            'send',
            $this->mapper->toEmail($notification, $recipient, $this->from),
        );

        if (!$this->boolProperty($result, 'successful')) {
            $message = $this->nullableStringProperty($result, 'error') ?? 'Failed to send auth notification.';

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

    private function boolProperty(object $target, string $property): bool
    {
        return $this->property($target, $property) === true;
    }

    private function invoke(object $target, string $method, mixed ...$arguments): object
    {
        if (!method_exists($target, $method)) {
            throw new \RuntimeException(sprintf(
                'TalkingBytes object "%s" does not support method "%s".',
                $target::class,
                $method,
            ));
        }

        return $this->requireObject($target->{$method}(...$arguments), sprintf('%s::%s', $target::class, $method));
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

    private function mailbox(string $value): object
    {
        $class = 'Infocyph\\TalkingBytes\\Email\\ValueObject\\EmailAddress';

        if (!class_exists($class)) {
            throw new \RuntimeException('Foundation auth notifications require infocyph/talkingbytes.');
        }

        return $this->requireObject($class::fromMailbox($value), 'TalkingBytes EmailAddress');
    }

    private function nullableStringProperty(object $target, string $property): ?string
    {
        $value = $this->property($target, $property);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function property(object $target, string $property): mixed
    {
        if (!property_exists($target, $property)) {
            throw new \RuntimeException(sprintf(
                'TalkingBytes object "%s" is missing property "%s".',
                $target::class,
                $property,
            ));
        }

        return $target->{$property};
    }

    private function requireObject(mixed $value, string $context): object
    {
        if (!is_object($value)) {
            throw new \RuntimeException(sprintf('%s must resolve to an object.', $context));
        }

        return $value;
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
                return $this->stringProperty($this->mailbox($candidate), 'email');
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

    private function stringProperty(object $target, string $property): string
    {
        $value = $this->property($target, $property);

        if (!is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf(
                'TalkingBytes object "%s" property "%s" must be a non-empty string.',
                $target::class,
                $property,
            ));
        }

        return $value;
    }
}
