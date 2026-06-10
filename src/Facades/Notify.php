<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\AuthLayer\Contract\Notification\AuthNotifierInterface;
use Infocyph\TalkingBytes\Email\Emailer;

final class Notify extends Facade
{
    public static function auth(): AuthNotifierInterface
    {
        return static::manager()->authNotifier();
    }

    public static function emailer(): Emailer
    {
        return static::manager()->emailer();
    }

    public static function manager(): \Infocyph\Foundation\Notifications\NotificationManager
    {
        return static::app()->notifications();
    }
}
