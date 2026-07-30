<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Messaging\MessagingManager;

final class Event extends ManagerFacade
{
    public static function manager(): MessagingManager
    {
        return self::app()->messaging();
    }
}
