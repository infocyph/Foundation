<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Cache\CacheManager;

/**
 * @mixin CacheManager
 */
final class Cache extends ManagerFacade
{
    public static function manager(): CacheManager
    {
        return self::app()->cache();
    }
}
