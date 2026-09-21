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

        $configured = $config->get('validation.schemas', []);
        if (is_array($configured)) {
            foreach ($configured as $name => $schema) {
                if (!is_string($name) || $name === '' || !is_array($schema)) {
                    continue;
                }

                if ($registry->has($name)) {
                    $registry->replace($name, $schema);
                } else {
                    $registry->define($name, $schema);
                }
            }
        }

        $extensions = $config->get('validation.extend', []);
        if (is_array($extensions)) {
            foreach ($extensions as $name => $schema) {
                if (is_string($name) && $name !== '' && is_array($schema)) {
                    $registry->extend($name, $schema);
                }
            }
        }

        return $registry->freeze();
    }
}
