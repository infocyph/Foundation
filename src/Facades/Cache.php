<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\Foundation\Cache\CacheManager;

final class Cache extends Facade
{
    public static function manager(): CacheManager
    {
        return self::app()->cache();
    }

    public static function memoizer(): Memoizer
    {
        return self::manager()->memoizer();
    }

    public static function once(): OnceMemoizer
    {
        return self::manager()->once();
    }

    public static function store(?string $name = null): CacheInterface
    {
        return self::manager()->store($name);
    }
}
