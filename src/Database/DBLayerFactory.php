<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;

final class DBLayerFactory
{
    /**
     * @var array<string, string>
     */
    private array $registered = [];

    public function __construct(
        private readonly DatabaseConnectionResolver $resolver,
    ) {}

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = $this->resolver->connectionName($name);
        $config = ConnectionConfig::fromArray($this->resolver->configuration($name));
        $signature = $this->signature($config);

        if (($this->registered[$name] ?? null) !== $signature || !DB::hasConnection($name)) {
            DB::addConnection($config, $name);
            $this->registered[$name] = $signature;
        }

        return DB::connection($name, $fresh);
    }

    public function resolver(): DatabaseConnectionResolver
    {
        return $this->resolver;
    }

    private function signature(ConnectionConfig $config): string
    {
        return hash('sha256', json_encode($config->toSafeArray(), JSON_THROW_ON_ERROR));
    }
}
