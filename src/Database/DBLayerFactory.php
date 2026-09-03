<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

final class DBLayerFactory
{
    /** @var array<string, ConnectionConfig> */
    private array $configurations = [];

    public function __construct(
        private readonly DatabaseConnectionResolver $resolver,
        private readonly ContainerInterface $container,
    ) {}

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = $this->resolver->connectionName($name);
        $config = $this->configurations[$name]
            ??= ConnectionConfig::fromArray($this->resolver->configuration($name));
        $state = $this->executionState();

        return $fresh
            ? $state->freshConnection($name, $config)
            : $state->connection($name, $config);
    }

    public function resolver(): DatabaseConnectionResolver
    {
        return $this->resolver;
    }

    private function executionState(): RuntimeExecutionState
    {
        $state = $this->container->get(RuntimeExecutionState::class);
        if (!$state instanceof RuntimeExecutionState) {
            throw new \LogicException('RuntimeExecutionState binding is invalid.');
        }

        return $state;
    }
}
