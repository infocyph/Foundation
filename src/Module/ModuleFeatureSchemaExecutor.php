<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;

/**
 * Only feature-owned schemas may be provisioned; built-in auth/session
 * schemas remain Core-owned and are never reached by module actions.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleFeatureSchemaExecutor
{
    private ModuleFeaturePlanner $planner;

    public function __construct(
        private Application $application,
        ?ModuleFeaturePlanner $planner = null,
    ) {
        $this->planner = $planner ?? new ModuleFeaturePlanner();
    }

    /**
     * @param list<string> $features
     * @phpstan-param Context $context
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function install(array $features, array $context, ?string $connection = null, bool $dryRun = false): array
    {
        $plan = $this->planner->plan('repair', $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature schema provision is blocked: ' . implode('; ', $plan['blockers']));
        }

        if ($plan['effects']['schemas'] === []) {
            return [];
        }

        // There is exactly one selected optional schema owner in Foundation 4.
        if ($plan['effects']['schemas'] !== ['messaging']
            || !in_array('execution.messaging', $plan['required'], true)
        ) {
            throw new \RuntimeException('Unexpected optional schema ownership.');
        }
        if (!$this->durableEnabled($context['config'])) {
            return [];
        }

        $this->assertOwnedPackages($context);

        if ($dryRun) {
            return [];
        }

        $manager = new ModuleSchemaManager($this->application, new ModuleCatalog());
        $result = $manager->install('messaging', $connection, true);
        $this->assertReady($result);

        return $result;
    }

    /**
     * @phpstan-param Context $context
     */
    private function assertOwnedPackages(array $context): void
    {
        foreach (['infocyph/omnibus', 'infocyph/dblayer'] as $package) {
            if (!isset($context['installed'][$package], $context['root_requirements'][$package])) {
                throw new \RuntimeException(sprintf('Install direct required package %s before schema work.', $package));
            }
        }
    }

    /**
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $results
     */
    private function assertReady(array $results): void
    {
        foreach ($results as $status) {
            if (!$status['applicable'] || !$status['installed']) {
                throw new \RuntimeException('Messaging schema is not ready: ' . $status['detail']);
            }
        }
    }

    /** @param array<string,mixed> $config */
    private function durableEnabled(array $config): bool
    {
        $messaging = $config['messaging'] ?? null;
        if (!is_array($messaging)) {
            return false;
        }

        $durable = $messaging['durable'] ?? null;

        return is_array($durable) && ($durable['enabled'] ?? false) === true;
    }
}
