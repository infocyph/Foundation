<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Data\DataManager;

/** @mixin DataManager */
final class Data extends ManagerFacade
{
    public static function manager(): DataManager
    {
        return self::app()->data();
    }
}
