<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class NotificationTemplateRegistry
{
    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array DEFAULTS = [
        'account_locked' => [
            'Your account was locked',
            "Your account has been locked.\n\n{{payload_lines}}",
        ],
        'delegated_access_granted' => [
            'Delegated access granted',
            "Delegated access was granted.\n\n{{payload_lines}}",
        ],
        'delegated_access_revoked' => [
            'Delegated access revoked',
            "Delegated access was revoked.\n\n{{payload_lines}}",
        ],
        'email_verification_requested' => [
            'Verify your email address',
            "Verify your email address to finish setup.\n\nEmail: {{email}}\nToken: {{token}}",
        ],
        'login_alert' => [
            'New login detected',
            "A new login was detected for your account.\n\n{{payload_lines}}",
        ],
        'mfa_challenge_requested' => [
            'Multi-factor authentication required',
            "A multi-factor challenge was issued for your account.\n\nChallenge ID: {{challenge_id}}\nFactor ID: {{factor_id}}\nPurpose: {{purpose}}",
        ],
        'new_device_alert' => [
            'New device sign-in',
            "A new device was used to access your account.\n\n{{payload_lines}}",
        ],
        'passkey_registered' => [
            'A passkey was added',
            "A passkey was registered for your account.\n\nCredential ID: {{credential_id}}",
        ],
        'passkey_removed' => [
            'A passkey was removed',
            "A passkey was removed from your account.\n\nCredential ID: {{credential_id}}",
        ],
        'password_changed' => [
            'Your password was changed',
            "Your password was changed successfully.\n\n{{payload_lines}}",
        ],
        'password_reset_requested' => [
            'Reset your password',
            "A password reset was requested for your account.\n\nToken: {{token}}\nRequest ID: {{request_id}}",
        ],
        'passwordless_login_requested' => [
            'Your passwordless login token',
            "Use the token below to sign in.\n\nIdentifier: {{identifier}}\nToken: {{token}}",
        ],
        'suspicious_activity' => [
            'Suspicious activity detected',
            "We detected suspicious activity on your account.\n\n{{payload_lines}}",
        ],
    ];

    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * @return array{subject: string, text: string, html: ?string}
     */
    public function for(AuthNotificationType $type): array
    {
        $defaults = $this->defaults($type);
        $overrides = ValueNormalizer::associativeArray($this->config->get('notifications.auth.templates.' . $type->value, []));

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
        [$subject, $text] = self::DEFAULTS[$type->value];

        return ['subject' => $subject, 'text' => $text, 'html' => null];
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
