<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Notifications\NotificationManager;

final class Notify extends Facade
{
    public static function auth(): AuthNotifierInterface
    {
        return self::manager()->authNotifier();
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
