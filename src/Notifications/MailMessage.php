<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\TalkingBytes\Email\EmailMessage;

/**
 * Application mail contract over TalkingBytes' native immutable EmailMessage.
 *
 * TalkingBytes remains the message-building and transport API; Foundation only
 * adds application sender-profile selection.
 */
abstract class MailMessage
{
    public function senderProfile(): ?string
    {
        return null;
    }

    abstract public function toEmail(): EmailMessage;
}
