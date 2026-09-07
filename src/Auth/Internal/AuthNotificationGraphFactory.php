<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Notifications\EmailProfiles;

final class AuthNotificationGraphFactory
{
    /** @param list<string> $criticalTypes */
    public static function talkingBytes(
        EmailProfiles $profiles,
        AuthNotificationMapper $mapper,
        AccountProviderInterface $accounts,
        array $criticalTypes,
        bool $failSilently,
        ?string $from,
    ): AuthNotifierInterface {
        return new TalkingBytesAuthNotifier(
            emailer: $profiles->authEmailer(),
            mapper: $mapper,
            accounts: $accounts,
            criticalTypes: $criticalTypes,
            failSilently: $failSilently,
            from: $from,
        );
    }
}
