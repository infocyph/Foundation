<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\AuthLayer\Notification\AuthNotificationType;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class NotificationTemplateRegistry
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public function for(AuthNotificationType $type): array
    {
        $defaults = $this->defaults($type);
        $overrides = $this->config->get('notifications.auth.templates.' . $type->value, []);
        if (!is_array($overrides)) {
            $overrides = [];
        }

        return [
            'subject' => $this->stringValue($overrides, 'subject', $defaults['subject']),
            'text' => $this->stringValue($overrides, 'text', $defaults['text']),
            'html' => $this->nullableStringValue($overrides, 'html', $defaults['html']),
        ];
    }

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    private function defaults(AuthNotificationType $type): array
    {
        return match ($type) {
            AuthNotificationType::PASSWORD_RESET_REQUESTED => [
                'subject' => 'Reset your password',
                'text' => "A password reset was requested for your account.\n\nToken: {{token}}\nRequest ID: {{request_id}}",
                'html' => null,
            ],
            AuthNotificationType::EMAIL_VERIFICATION_REQUESTED => [
                'subject' => 'Verify your email address',
                'text' => "Verify your email address to finish setup.\n\nEmail: {{email}}\nToken: {{token}}",
                'html' => null,
            ],
            AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED => [
                'subject' => 'Your passwordless login token',
                'text' => "Use the token below to sign in.\n\nIdentifier: {{identifier}}\nToken: {{token}}",
                'html' => null,
            ],
            AuthNotificationType::MFA_CHALLENGE_REQUESTED => [
                'subject' => 'Multi-factor authentication required',
                'text' => "A multi-factor challenge was issued for your account.\n\nChallenge ID: {{challenge_id}}\nFactor ID: {{factor_id}}\nPurpose: {{purpose}}",
                'html' => null,
            ],
            AuthNotificationType::PASSWORD_CHANGED => [
                'subject' => 'Your password was changed',
                'text' => "Your password was changed successfully.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::PASSKEY_REGISTERED => [
                'subject' => 'A passkey was added',
                'text' => "A passkey was registered for your account.\n\nCredential ID: {{credential_id}}",
                'html' => null,
            ],
            AuthNotificationType::PASSKEY_REMOVED => [
                'subject' => 'A passkey was removed',
                'text' => "A passkey was removed from your account.\n\nCredential ID: {{credential_id}}",
                'html' => null,
            ],
            AuthNotificationType::ACCOUNT_LOCKED => [
                'subject' => 'Your account was locked',
                'text' => "Your account has been locked.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::SUSPICIOUS_ACTIVITY => [
                'subject' => 'Suspicious activity detected',
                'text' => "We detected suspicious activity on your account.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::NEW_DEVICE_ALERT => [
                'subject' => 'New device sign-in',
                'text' => "A new device was used to access your account.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::DELEGATED_ACCESS_GRANTED => [
                'subject' => 'Delegated access granted',
                'text' => "Delegated access was granted.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::DELEGATED_ACCESS_REVOKED => [
                'subject' => 'Delegated access revoked',
                'text' => "Delegated access was revoked.\n\n{{payload_lines}}",
                'html' => null,
            ],
            AuthNotificationType::LOGIN_ALERT => [
                'subject' => 'New login detected',
                'text' => "A new login was detected for your account.\n\n{{payload_lines}}",
                'html' => null,
            ],
        };
    }

    /**
     * @param array<string, mixed> $values
     */
    private function nullableStringValue(array $values, string $key, ?string $default): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringValue(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }
}
