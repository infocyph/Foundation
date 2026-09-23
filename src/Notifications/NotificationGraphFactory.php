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

        return EmailLimits::fromArray($limits);
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
