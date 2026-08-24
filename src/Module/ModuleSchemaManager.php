<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Module\Internal\CacheSchemaManager;
use Infocyph\Foundation\Session\SessionDatabaseSchema;

final readonly class ModuleSchemaManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
    ) {}

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function install(string $module, ?string $connection = null, bool $applicableOnly = false): array
    {
        $definition = $this->catalog->resolve($module);
        $results = [];

        foreach ($definition['schemas'] as $schema) {
            $before = $this->schemaStatuses($definition['name'], $schema, $connection);
            $applicable = array_any($before, static fn(array $status): bool => $status['applicable']);
            $available = array_any($before, static fn(array $status): bool => $status['state'] !== 'unavailable');

            if (($applicableOnly && !$applicable) || !$available) {
                array_push($results, ...$before);

                continue;
            }

            $this->installSchema($schema, $connection);
            array_push(
                $results,
                ...$this->schemaStatuses($definition['name'], $schema, $connection, true),
            );
        }

        return $results;
    }

    /**
     * Provision every schema currently required by configured application capabilities.
     *
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function installApplicable(?string $connection = null): array
    {
        $results = [];

        foreach (array_keys($this->catalog->all()) as $module) {
            foreach ($this->install($module, $connection, true) as $schema) {
                if ($schema['applicable']) {
                    $results[] = $schema;
                }
            }
        }

        return $results;
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function status(string $module, ?string $connection = null): array
    {
        $definition = $this->catalog->resolve($module);
        $results = [];

        foreach ($definition['schemas'] as $schema) {
            array_push($results, ...$this->schemaStatuses($definition['name'], $schema, $connection));
        }

        return $results;
    }

    private function authApplicable(): bool
    {
        return $this->application->config()->get('auth.drivers.storage', 'memory') === 'database';
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function authStatus(string $module, ?string $connection, bool $afterInstall): array
    {
        $applicable = $this->authApplicable();
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return $this->result(
                'auth',
                $module,
                $applicable,
                false,
                'unavailable',
                'Requires the database module; run "php infbyte module:install database".',
            );
        }

        try {
            $status = $this->application->make(AuthSchemaInstaller::class)->readiness($connection);
        } catch (\Throwable $failure) {
            return $this->result('auth', $module, $applicable, false, 'unavailable', $failure->getMessage());
        }

        return $this->result(
            'auth',
            $module,
            $applicable,
            $status['installed'],
            $status['installed'] ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
            $status['installed']
                ? 'Authentication tables are installed.'
                : 'Missing: ' . implode(', ', [...$status['missing_tables'], ...$status['missing_columns']]),
        );
    }

    private function cacheSchemas(): CacheSchemaManager
    {
        return new CacheSchemaManager($this->application);
    }

    private function installSchema(string $schema, ?string $connection): void
    {
        match ($schema) {
            'auth' => $this->application->make(AuthSchemaInstaller::class)->install($connection),
            'cache' => $this->cacheSchemas()->install($connection),
            'session' => $this->application->make(SessionDatabaseSchema::class)->install($connection),
            default => null,
        };
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function result(
        string $name,
        string $module,
        bool $applicable,
        bool $installed,
        string $state,
        string $detail,
    ): array {
        return [
            'name' => $name,
            'module' => $module,
            'applicable' => $applicable,
            'installed' => $installed,
            'state' => $state,
            'detail' => $detail,
        ];
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    private function schemaStatuses(
        string $module,
        string $schema,
        ?string $connection,
        bool $afterInstall = false,
    ): array {
        return match ($schema) {
            'auth' => [$this->authStatus($module, $connection, $afterInstall)],
            'cache' => $this->cacheSchemas()->statuses($module, $connection, $afterInstall),
            'session' => [$this->sessionStatus($module, $connection, $afterInstall)],
            default => [$this->result($schema, $module, false, true, 'not-applicable', 'No schema provisioner is registered.')],
        };
    }

    private function sessionApplicable(): bool
    {
        return $this->application->config()->get('session.driver', 'file') === 'database';
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function sessionStatus(string $module, ?string $connection, bool $afterInstall): array
    {
        $applicable = $this->sessionApplicable();
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return $this->result(
                'session',
                $module,
                $applicable,
                false,
                'unavailable',
                'Requires the database module; run "php infbyte module:install database".',
            );
        }

        try {
            $status = $this->application->make(SessionDatabaseSchema::class)->readiness($connection);
        } catch (\Throwable $failure) {
            return $this->result('session', $module, $applicable, false, 'unavailable', $failure->getMessage());
        }

        return $this->result(
            'session',
            $module,
            $applicable,
            $status['installed'],
            $status['installed'] ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
            $status['installed'] ? 'Session table is installed.' : 'Missing session table ' . $status['table'] . '.',
        );
    }
}
