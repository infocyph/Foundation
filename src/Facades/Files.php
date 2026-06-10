<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Filesystem\PathManager;

final class Files extends Facade
{
    public static function base(string $path = ''): string
    {
        return static::manager()->base($path);
    }

    public static function cache(string $path = ''): string
    {
        return static::manager()->cache($path);
    }

    public static function config(string $path = ''): string
    {
        return static::manager()->config($path);
    }

    public static function logs(string $path = ''): string
    {
        return static::manager()->logs($path);
    }

    public static function manager(): PathManager
    {
        return static::app()->paths();
    }

    public static function storage(string $path = ''): string
    {
        return static::manager()->storage($path);
    }
}
