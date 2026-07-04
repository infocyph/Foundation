<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Cache\CacheManager;

final class Cache extends Facade
{
    public static function manager(): CacheManager
    {
        return self::app()->cache();
    }

    public static function store(?string $name = null): CacheInterface
    {
        return self::manager()->store($name);
    }
}
