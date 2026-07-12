<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use DateTimeInterface;
use Infocyph\Foundation\Identifiers\IdentifierManager;
use Infocyph\UID\Configuration\RandflakeConfig;
use Infocyph\UID\Configuration\SnowflakeConfig;
use Infocyph\UID\Configuration\SonyflakeConfig;
use Infocyph\UID\Configuration\TBSLConfig;
use Infocyph\UID\Contracts\IdValueInterface;
use Infocyph\UID\Enums\UlidGenerationMode;
use Infocyph\UID\Sequence\SequenceProviderInterface;

final class Ids extends Facade
{
    public static function compare(IdValueInterface|string $left, IdValueInterface|string $right): int
    {
        return self::manager()->compare($left, $right);
    }

    public static function cuid2(?int $length = null): string
    {
        return self::manager()->cuid2($length);
    }

    public static function deterministic(string $payload, ?int $length = null, ?string $namespace = null): string
    {
        return self::manager()->deterministic($payload, $length, $namespace);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function generate(?string $driver = null, array $options = []): int|string
    {
        return self::manager()->generate($driver, $options);
    }

    public static function generateForAuth(string $key): string
    {
        return self::manager()->generateForAuth($key);
    }

    public static function isValid(string $driver, string $id): bool
    {
        return self::manager()->isValid($driver, $id);
    }

    public static function ksuid(?DateTimeInterface $dateTime = null): string
    {
        return self::manager()->ksuid($dateTime);
    }

    public static function manager(): IdentifierManager
    {
        return self::app()->ids();
    }

    public static function nanoId(?int $length = null): string
    {
        return self::manager()->nanoId($length);
    }

    public static function opaque(?int $length = null): string
    {
        return self::manager()->opaque($length);
    }

    public static function opaqueFromInt(int $value, ?string $salt = null): string
    {
        return self::manager()->opaqueFromInt($value, $salt);
    }

    public static function opaqueToInt(string $token, ?string $salt = null): int
    {
        return self::manager()->opaqueToInt($token, $salt);
    }

    /**
     * @return array<string, mixed>
     * @param array<string, mixed> $options
     */
    public static function parse(string $driver, string $id, array $options = []): array
    {
        return self::manager()->parse($driver, $id, $options);
    }

    public static function randflake(?RandflakeConfig $config = null): int|string
    {
        return self::manager()->randflake($config);
    }

    public static function randflakeString(?RandflakeConfig $config = null): string
    {
        return self::manager()->randflakeString($config);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public static function sequenceProvider(?array $config = null): SequenceProviderInterface
    {
        return self::manager()->sequenceProvider($config);
    }

    public static function snowflake(?SnowflakeConfig $config = null): int|string
    {
        return self::manager()->snowflake($config);
    }

    public static function sonyflake(?SonyflakeConfig $config = null): int|string
    {
        return self::manager()->sonyflake($config);
    }

    /**
     * @param array<int, IdValueInterface|string> $ids
     * @return array<int, IdValueInterface|string>
     */
    public static function sort(array $ids): array
    {
        return self::manager()->sort($ids);
    }

    public static function tbsl(?TBSLConfig $config = null): int|string
    {
        return self::manager()->tbsl($config);
    }

    public static function ulid(?DateTimeInterface $dateTime = null, UlidGenerationMode|string|null $mode = null): string
    {
        return self::manager()->ulid($dateTime, $mode);
    }

    public static function uuid(?DateTimeInterface $dateTime = null, ?string $node = null): string
    {
        return self::manager()->uuid($dateTime, $node);
    }

    public static function uuid1(?string $node = null): string
    {
        return self::manager()->uuid1($node);
    }

    public static function uuid3(string $namespace, string $name): string
    {
        return self::manager()->uuid3($namespace, $name);
    }

    public static function uuid4(): string
    {
        return self::manager()->uuid4();
    }

    public static function uuid5(string $namespace, string $name): string
    {
        return self::manager()->uuid5($namespace, $name);
    }

    public static function uuid6(?string $node = null): string
    {
        return self::manager()->uuid6($node);
    }

    public static function uuid7(?DateTimeInterface $dateTime = null, ?string $node = null): string
    {
        return self::manager()->uuid7($dateTime, $node);
    }

    public static function uuid8(?string $node = null): string
    {
        return self::manager()->uuid8($node);
    }

    public static function uuidFromBase(string $encoded, int $base): string
    {
        return self::manager()->uuidFromBase($encoded, $base);
    }

    public static function uuidFromBytes(string $bytes): string
    {
        return self::manager()->uuidFromBytes($bytes);
    }

    public static function uuidToBase(string $uuid, int $base): string
    {
        return self::manager()->uuidToBase($uuid, $base);
    }

    public static function uuidToBytes(string $uuid): string
    {
        return self::manager()->uuidToBytes($uuid);
    }

    public static function xid(): string
    {
        return self::manager()->xid();
    }
}
