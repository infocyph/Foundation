<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\CacheLayer\Cache\CacheInterface;

final class Cache extends Facade
{
    public static function manager(): \Infocyph\Foundation\Cache\CacheManager
    {
        return static::app()->cache();
    }

    public static function store(?string $name = null): CacheInterface
    {
        return static::manager()->store($name);
    }
}
