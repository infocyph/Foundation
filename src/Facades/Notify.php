<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Notifications\NotificationManager;
use Infocyph\TalkingBytes\Email\Dkim\DkimPublicKeyResolver;
use Infocyph\TalkingBytes\Email\Dkim\DkimVerifier;

final class Notify extends Facade
{
    public static function auth(): AuthNotifierInterface
    {
        return self::manager()->authNotifier();
    }

    public static function dkimVerifier(?DkimPublicKeyResolver $resolver = null): DkimVerifier
    {
        return self::manager()->dkimVerifier($resolver);
    }

    public static function emailer(): object
    {
        return self::manager()->emailer();
    }

    public static function manager(): NotificationManager
    {
        return self::app()->notifications();
    }
}
