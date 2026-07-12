<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\ArrayKit\Collection\HookedCollection;
use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\ArrayKit\Collection\Pipeline;
use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\ArrayKit\Facade\ModuleProxy;
use Infocyph\Foundation\Data\DataManager;

final class Data extends Facade
{
    public static function collection(mixed $data = []): Collection
    {
        return self::manager()->collection($data);
    }

    /**
     * @param array<array-key, mixed> $items
     */
    public static function config(array $items = []): Config
    {
        return self::manager()->config($items);
    }

    public static function dot(): ModuleProxy
    {
        return self::manager()->dot();
    }

    public static function dotenv(): ModuleProxy
    {
        return self::manager()->dotenv();
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string> $mapping
     */
    public static function dto(
        array $values = [],
        ?string $class = null,
        array $mapping = [],
        bool $nested = false,
        bool $coerce = false,
    ): object {
        return self::manager()->dto($values, $class, $mapping, $nested, $coerce);
    }

    public static function env(?string $key = null, mixed $default = null): mixed
    {
        return self::manager()->env($key, $default);
    }

    public static function environment(): ModuleProxy
    {
        return self::manager()->environment();
    }

    public static function helper(): ModuleProxy
    {
        return self::manager()->helper();
    }

    public static function hookedCollection(mixed $data = []): HookedCollection
    {
        return self::manager()->hookedCollection($data);
    }

    /**
     * @return LazyCollection<array-key, mixed>
     */
    public static function lazyCollection(mixed $data = []): LazyCollection
    {
        return self::manager()->lazyCollection($data);
    }

    /**
     * @param array<array-key, mixed> $items
     */
    public static function lazyConfig(
        ?string $directory = null,
        string $extension = 'php',
        array $items = [],
        ?string $namespaceCacheDirectory = null,
    ): LazyFileConfig {
        return self::manager()->lazyConfig($directory, $extension, $items, $namespaceCacheDirectory);
    }

    public static function manager(): DataManager
    {
        return self::app()->data();
    }

    public static function multi(): ModuleProxy
    {
        return self::manager()->multi();
    }

    public static function pipeline(mixed $data): Pipeline
    {
        return self::manager()->pipeline($data);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param array<string, string> $shape
     * @return array<array-key, mixed>
     */
    public static function requireShape(array $row, array $shape): array
    {
        return self::manager()->requireShape($row, $shape);
    }

    public static function single(): ModuleProxy
    {
        return self::manager()->single();
    }
}
