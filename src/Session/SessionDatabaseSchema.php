<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class SessionDatabaseSchema
{
    public function __construct(
        private SessionConfig $config,
        private DBLayerFactory $database,
    ) {}

    public function install(?string $connection = null): void
    {
        $schema = $this->schema($connection);
        if ($schema->hasTable($this->config->databaseTable)) {
            return;
        }

        $schema->create($this->config->databaseTable, static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->text('payload');
            $table->bigInteger('expires_at');
            $table->index('expires_at');
        });
    }

    /**
     * @return array{installed:bool,table:string,connection:string|null}
     */
    public function readiness(?string $connection = null): array
    {
        return [
            'installed' => $this->schema($connection)->hasTable($this->config->databaseTable),
            'table' => $this->config->databaseTable,
            'connection' => $connection ?? $this->config->databaseConnection,
        ];
    }

    private function schema(?string $connection): SchemaManager
    {
        return new SchemaManager(
            $this->database->connection($connection ?? $this->config->databaseConnection),
        );
    }
}
