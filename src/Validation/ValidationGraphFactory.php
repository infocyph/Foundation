<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\ReqShield\Bridge\DBLayerDatabaseProvider;
use Infocyph\ReqShield\Schema\SchemaRegistry;

final class ValidationGraphFactory
{
    public static function databaseProvider(
        DBLayerFactory $database,
        ?string $connection,
    ): DBLayerDatabaseProvider {
        return new DBLayerDatabaseProvider(
            static fn() => $database->connection($connection),
        );
    }

    public static function schemaRegistry(ConfigRepository $config): SchemaRegistry
    {
        $registry = new SchemaRegistry(AuthRequestSchemas::all());

        self::applySchemas($registry, $config->get('validation.schemas', []));
        self::applyExtensions($registry, $config->get('validation.extend', []));

        return $registry->freeze();
    }

    private static function applyExtensions(SchemaRegistry $registry, mixed $schemas): void
    {
        foreach (self::schemaMap($schemas) as $name => $schema) {
            $registry->extend($name, $schema);
        }
    }

    private static function applySchemas(SchemaRegistry $registry, mixed $schemas): void
    {
        foreach (self::schemaMap($schemas) as $name => $schema) {
            if ($registry->has($name)) {
                $registry->replace($name, $schema);

                continue;
            }

            $registry->define($name, $schema);
        }
    }

    /** @return array<string, array<int|string, mixed>> */
    private static function schemaMap(mixed $schemas): array
    {
        if (!is_array($schemas)) {
            return [];
        }

        $normalized = [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name) && $name !== '' && is_array($schema)) {
                $normalized[$name] = $schema;
            }
        }

        return $normalized;
    }
}
