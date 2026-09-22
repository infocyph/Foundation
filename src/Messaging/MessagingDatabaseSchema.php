<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Integration\DBLayer\QueueSchema;

final readonly class MessagingDatabaseSchema
{
public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $database,
    ) {}

public function install(?string $connection = null): void
    {
        $state = $this->readiness($connection);
        if ($state['installed']) {
            return;
        }
        if ($state['installed_tables'] !== []) {
            throw new ConfigurationException(
                'Omnibus messaging schema is partially installed. Complete or restore the coordinated durable-schema cutover before retrying.',
            );
        }

        $database = $this->connection($connection);
        $tables = $this->tables();
        foreach (QueueSchema::statements(
            $database->getDriverName(),
            $tables['messages'],
            $tables['failures'],
            $tables['workflows'],
            $tables['workflow_items'],
        ) as $statement) {
            $database->statement($statement);
        }
    }

/**
     * @return array{
     *   installed:bool,
     *   installed_tables:list<string>,
     *   missing_tables:list<string>,
     *   connection:string|null
     * }
     */
    public function readiness(?string $connection = null): array
    {
        $schema = new SchemaManager($this->connection($connection));
        $installed = [];
        $missing = [];

        foreach ($this->tables() as $table) {
            if ($schema->hasTable($table)) {
                $installed[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return [
            'installed' => $missing === [],
            'installed_tables' => $installed,
            'missing_tables' => $missing,
            'connection' => $connection ?? $this->connectionName(),
        ];
    }

private function connection(?string $connection): Connection
    {
        return $this->database->infrastructureConnection($connection ?? $this->connectionName());
    }

private function connectionName(): ?string
    {
        $connection = $this->config->get('messaging.durable.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

private function table(string $name, string $default): string
    {
        return ValueNormalizer::string(
            $this->config->get('messaging.durable.tables.' . $name),
            $default,
        );
    }

/** @return array{messages:string,failures:string,workflows:string,workflow_items:string} */
    private function tables(): array
    {
        return [
            'messages' => $this->table('messages', 'omnibus_messages'),
            'failures' => $this->table('failures', 'omnibus_failures'),
            'workflows' => $this->table('workflows', 'omnibus_workflows'),
            'workflow_items' => $this->table('workflow_items', 'omnibus_workflow_items'),
        ];
    }
}
