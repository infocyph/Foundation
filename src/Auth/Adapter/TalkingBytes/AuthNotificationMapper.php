<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\TalkingBytes;

use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;

final readonly class AuthNotificationMapper
{
    public function __construct(
        private NotificationTemplateRegistry $templates,
    ) {}

    public function toEmail(AuthNotification $notification, string $recipient, ?string $from = null): object
    {
        $template = $this->templates->for($notification->type);
        $variables = $this->variables($notification);
        $text = $this->render($template['text'], $variables);
        $html = $template['html'] !== null
            ? $this->render($template['html'], $variables)
            : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $message = $this->emailMessage();
        $message = $this->invoke($message, 'to', $recipient);
        $message = $this->invoke($message, 'subject', $this->render($template['subject'], $variables));
        $message = $this->invoke($message, 'text', $text);
        $message = $this->invoke($message, 'html', $html);
        $message = $this->invoke($message, 'withMetadata', [
            'account_id' => $notification->accountId,
            'auth_notification_type' => $notification->type->value,
        ] + $notification->payload);

        if ($from === null || $from === '') {
            return $message;
        }

        $address = $this->mailbox($from);
        $email = $this->stringProperty($address, 'email');
        $name = $this->nullableStringProperty($address, 'name');

        return $name === null
            ? $this->invoke($message, 'from', $email)
            : $this->invoke($message, 'from', $email, $name);
    }

    private function emailMessage(): object
    {
        $class = 'Infocyph\\TalkingBytes\\Email\\EmailMessage';

        if (!class_exists($class)) {
            throw new \RuntimeException('Foundation auth notifications require infocyph/talkingbytes.');
        }

        return $this->requireObject($class::new(), 'TalkingBytes EmailMessage');
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

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadLines(array $payload): string
    {
        $lines = [];

        foreach ($payload as $key => $value) {
            $rendered = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $lines[] = sprintf('%s: %s', $key, $rendered);
        }

        return implode("\n", $lines);
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

    /**
     * @param array<string, scalar|null> $variables
     */
    private function render(string $template, array $variables): string
    {
        $rendered = $template;

        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{' . $key . '}}', (string) ($value ?? ''), $rendered);
        }

        return preg_replace('/{{[a-z0-9_]+}}/i', '', $rendered) ?? $rendered;
    }

    private function requireObject(mixed $value, string $context): object
    {
        if (!is_object($value)) {
            throw new \RuntimeException(sprintf('%s must resolve to an object.', $context));
        }

        return $value;
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

    /**
     * @return array<string, scalar|null>
     */
    private function variables(AuthNotification $notification): array
    {
        $variables = [
            'account_id' => $notification->accountId,
            'notification_type' => $notification->type->value,
            'payload_lines' => $this->payloadLines($notification->payload),
        ];

        foreach ($notification->payload as $key => $value) {
            $variables[$key] = is_scalar($value) || $value === null
                ? $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $variables;
    }
}
