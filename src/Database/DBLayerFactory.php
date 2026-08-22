<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;

final class DBLayerFactory
{
    /** @var array<string, ConnectionConfig> */
    private array $configurations = [];

    /** @var array<string, true> */
    private array $registered = [];

    public function __construct(
        private readonly DatabaseConnectionResolver $resolver,
    ) {}

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        $default = $this->resolver->connectionName();
        $name = $this->resolver->connectionName($name);
        $config = $this->configurations[$name]
            ??= ConnectionConfig::fromArray($this->resolver->configuration($name));

        DB::setDefaultConnection($default);

        if (!isset($this->registered[$name]) || !DB::hasConnection($name)) {
            DB::addConnection($config, $name);
            $this->registered[$name] = true;
        }

        return DB::connection($name, $fresh);
    }

    public function resolver(): DatabaseConnectionResolver
    {
        return $this->resolver;
    }
}
