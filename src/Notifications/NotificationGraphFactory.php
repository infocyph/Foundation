<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Notifications;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

final class NotificationGraphFactory
{
    public static function emailer(EmailProfiles $profiles): Emailer
    {
        return $profiles->sender();
    }

    public static function emailLimits(ConfigRepository $config): EmailLimits
    {
        $limits = ValueNormalizer::associativeArray(
            $config->get('notifications.email.parsing.limits', []),
        );

        return new EmailLimits(
            maxMessageBytes: ValueNormalizer::int($limits['maxMessageBytes'] ?? 10 * 1024 * 1024, 10 * 1024 * 1024),
            maxAttachmentBytes: ValueNormalizer::int($limits['maxAttachmentBytes'] ?? 25 * 1024 * 1024, 25 * 1024 * 1024),
            maxAttachmentCount: ValueNormalizer::int($limits['maxAttachmentCount'] ?? 500, 500),
            maxDecodedBodyBytes: ValueNormalizer::int($limits['maxDecodedBodyBytes'] ?? 10 * 1024 * 1024, 10 * 1024 * 1024),
            maxMimeDepth: ValueNormalizer::int($limits['maxMimeDepth'] ?? 20, 20),
            maxMimeParts: ValueNormalizer::int($limits['maxMimeParts'] ?? 500, 500),
            maxHeaderBytes: ValueNormalizer::int($limits['maxHeaderBytes'] ?? 131072, 131072),
            maxHeaderCount: ValueNormalizer::int($limits['maxHeaderCount'] ?? 2000, 2000),
        );
    }

    public static function rawEmailParser(EmailLimits $limits): RawEmailParser
    {
        return new RawEmailParser(limits: $limits);
    }

    public static function spoolReceiver(EmailProfiles $profiles): SpoolEmailReceiver
    {
        return $profiles->spoolReceiver();
    }
}
