<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Database\DatabaseManager;

/**
 * @mixin DatabaseManager
 */
final class DB extends ManagerFacade
{
    public static function manager(): DatabaseManager
    {
        return self::app()->db();
    }
}
