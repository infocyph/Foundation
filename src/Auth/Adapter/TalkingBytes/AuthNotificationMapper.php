<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\TalkingBytes;

use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Template\ArrayVariableRenderer;
use Infocyph\TalkingBytes\Email\Template\TemplateRendererInterface;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

final readonly class AuthNotificationMapper
{
    public function __construct(
        private NotificationTemplateRegistry $templates,
        private TemplateRendererInterface $renderer = new ArrayVariableRenderer(),
    ) {}

    public function toEmail(AuthNotification $notification, string $recipient, ?string $from = null): EmailMessage
    {
        $template = $this->templates->for($notification->type);
        $variables = $this->variables($notification);
        $text = $this->renderTemplate($template['text'], $variables);
        $html = $template['html'] !== null
            ? $this->renderTemplate($template['html'], $variables)
            : nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $message = EmailMessage::new()
            ->to($recipient)
            ->subject($this->renderTemplate($template['subject'], $variables))
            ->text($text)
            ->html($html)
            ->withMetadata([
                'account_id' => $notification->accountId,
                'auth_notification_type' => $notification->type->value,
            ] + $notification->payload);

        if ($from === null || $from === '') {
            return $message;
        }

        $address = EmailAddress::fromMailbox($from);

        return $address->name === null
            ? $message->from($address->email)
            : $message->from($address->email, $address->name);
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
                : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            $lines[] = sprintf('%s: %s', $key, $rendered);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, scalar|null> $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        $rendered = $this->renderer->render($template, $variables);

        return preg_replace('/{{[a-z0-9_]+}}/i', '', $rendered) ?? $rendered;
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
                : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }

        return $variables;
    }
}
