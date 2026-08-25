<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\DeploymentTopology;
use Infocyph\Foundation\Config\SharedStateTopology;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class SessionTopologyGuard
{
    private SharedStateTopology $state;

    public function __construct(private ConfigRepository $config)
    {
        $this->state = new SharedStateTopology($config);
    }

    public function assert(SessionConfig $session): void
    {
        if (!$this->config->isProduction()) {
            return;
        }
        if ($session->driver === 'array') {
            throw new ConfigurationException('Production browser sessions must not use the process-local array store.');
        }
        if (!$session->lockEnabled) {
            throw new ConfigurationException('Production browser sessions require session.lock.enabled=true.');
        }

        if (DeploymentTopology::resolve($this->config) !== DeploymentTopology::DISTRIBUTED) {
            return;
        }

        $storeScope = match ($session->driver) {
            'cache' => $this->state->cacheStoreScope($session->cacheStore),
            'database' => $this->state->databaseConnectionScope($session->databaseConnection),
            'file' => SharedStateTopology::HOST,
            'array' => SharedStateTopology::PROCESS,
            default => SharedStateTopology::NONE,
        };
        if (!$this->state->satisfies($storeScope, SharedStateTopology::CLUSTER)) {
            throw new ConfigurationException(sprintf(
                'Distributed browser sessions require cluster-visible state; driver "%s" provides %s-visible state.',
                $session->driver,
                $storeScope,
            ));
        }

        $lockScope = $this->state->cacheStoreCoordinationScope($session->lockStore);
        if (!$this->state->satisfies($lockScope, SharedStateTopology::CLUSTER)) {
            throw new ConfigurationException(sprintf(
                'Distributed browser sessions require cluster-visible coordination; configured session locking is %s-visible.',
                $lockScope,
            ));
        }
    }
}
