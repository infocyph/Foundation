<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

/**
 * Application mail orchestration over native TalkingBytes EmailMessage/Emailer.
 */
final readonly class Mailer
{
    public function __construct(
        private EmailProfiles $profiles,
        private ConfigRepository $config,
    ) {}

    public function send(MailMessage $message): CommunicationResult
    {
        return $this->sendEmail($message->toEmail(), $message->senderProfile());
    }

    public function sendEmail(EmailMessage $message, ?string $profile = null): CommunicationResult
    {
        return $this->profiles->sender($profile)->send($this->applyDefaultFrom($message));
    }

    private function applyDefaultFrom(EmailMessage $message): EmailMessage
    {
        if ($message->envelope()->from !== null) {
            return $message;
        }

        $configured = $this->config->get('notifications.email.default_from');
        if (!is_string($configured) || trim($configured) === '') {
            return $message;
        }

        $address = EmailAddress::fromMailbox($configured);

        return $address->name === null
            ? $message->from($address->email)
            : $message->from($address->email, $address->name);
    }
}
