<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Closure;
use Infocyph\Foundation\Application\Application;

/**
 * B3 internal plan/apply boundary. Never interprets installation as activation,
 * never compensates Composer with an unsafe implicit package rollback, and
 * never drops application schema or stored messages.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 * @phpstan-type Phase array{name:string,status:string,detail:string}
 * @phpstan-type Outcome array{
 *     operation:string,features:list<string>,status:string,
 *     phases:list<Phase>,recovery:?string
 * }
 */
final readonly class ModuleFeatureLifecycleExecutor
{
    private ModuleActivationManager $activation;

    private ModuleFeatureComposerExecutor $composer;

    private ModuleFeatureConfigPublisher $configs;

    private ModuleFeaturePlanner $planner;

    private ModuleFeatureSchemaExecutor $schemas;

    public function __construct(
        Application $application,
        ?ModuleFeaturePlanner $planner = null,
        ?ModuleFeatureComposerExecutor $composer = null,
        ?ModuleFeatureConfigPublisher $configs = null,
        ?ModuleFeatureSchemaExecutor $schemas = null,
        ?ModuleActivationManager $activation = null,
    ) {
        $this->planner = $planner ?? new ModuleFeaturePlanner();
        $this->composer = $composer ?? new ModuleFeatureComposerExecutor($application);
        $this->configs = $configs ?? new ModuleFeatureConfigPublisher($application);
        $this->schemas = $schemas ?? new ModuleFeatureSchemaExecutor($application);
        $this->activation = $activation ?? new ModuleActivationManager($application, new ModuleCatalog());
    }

    /**
     * @param list<string> $features
     * @phpstan-param Context $context
     * @phpstan-param Closure():Context|null $refresh
     * @phpstan-return Outcome
     */
    public function apply(
        string $operation,
        array $features,
        array $context,
        ?Closure $refresh = null,
        bool $dryRun = false,
        ?string $connection = null,
    ): array {
        $plan = $this->planner->plan($operation, $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature lifecycle is blocked: ' . implode('; ', $plan['blockers']));
        }

        if ($dryRun) {
            return $this->outcome(
                $operation,
                $plan['requested'],
                'planned',
                [$this->phase('plan', 'complete', 'No mutations performed.')],
            );
        }

        if ($operation === 'enable' || $operation === 'disable') {
            $this->activation->applyFeatures($operation, $features, $context);

            return $this->outcome(
                $operation,
                $plan['requested'],
                'complete',
                [$this->phase('activation', 'complete', 'Only explicitly requested feature flags updated.')],
            );
        }

        if ($operation === 'remove') {
            if (!isset($context['selected_features'])) {
                throw new \RuntimeException('Selected feature ownership is unknown; cannot safely remove packages.');
            }
            $result = $this->composer->applyPackages('remove', $features, $context);
            if (!$result->successful()) {
                return $this->failed($operation, $plan['requested'], 'composer', $result->exitCode);
            }

            return $this->outcome(
                $operation,
                $plan['requested'],
                'complete',
                [$this->phase('composer', 'complete', 'Selected packages reconciled; schemas and data retained.')],
            );
        }

        return $this->applyInstallOrRepair($operation, $features, $context, $plan['requested'], $refresh, $connection);
    }

    /**
     * @param list<string> $features
     * @param list<string> $requested
     * @phpstan-param Context $context
     * @phpstan-param Closure():Context|null $refresh
     * @phpstan-return Outcome
     */
    private function applyInstallOrRepair(
        string $operation,
        array $features,
        array $context,
        array $requested,
        ?Closure $refresh,
        ?string $connection,
    ): array {
        // A phase snapshot is mandatory: Composer may have mutated the root
        // manifest and transitive graph, even if only some packages completed.
        if ($refresh === null) {
            throw new \RuntimeException('A fresh Composer snapshot callback is required for install/repair.');
        }

        $result = $this->composer->applyPackages($operation, $features, $context);
        if (!$result->successful()) {
            return $this->failed($operation, $requested, 'composer', $result->exitCode);
        }

        $phases = [$this->phase('composer', 'complete', 'Reconcile packages; no automatic rollback.')];

        try {
            $updated = $refresh();
            $postPlan = $this->planner->plan('repair', $features, $updated);
            if (!$postPlan['applicable'] || $postPlan['effects']['require'] !== []) {
                throw new \RuntimeException('Composer still has unresolved selected feature packages.');
            }
            $configs = $this->configs->publish($features, $updated);
            $phases[] = $this->phase('config', 'complete', sprintf('Published %d selected templates.', count($configs['published'])));
            $this->schemas->install($features, $updated, $connection);
            $phases[] = $this->phase('schema', 'complete', 'Applicable owner schemas verified without destructive reset.');
        } catch (\Throwable $failure) {
            $phases[] = $this->phase('reconcile', 'failed', $failure->getMessage());

            return $this->outcome(
                $operation,
                $requested,
                'partial',
                $phases,
                'Re-read Composer and configuration state; run repair for the same selected features.',
            );
        }

        return $this->outcome($operation, $requested, 'complete', $phases);
    }

    /**
     * @param list<string> $features
     * @phpstan-return Outcome
     */
    private function failed(string $operation, array $features, string $phase, int $exit): array
    {
        return $this->outcome(
            $operation,
            $features,
            'partial',
            [$this->phase($phase, 'failed', sprintf('Composer exited %d; no further phases ran.', $exit))],
            'Inspect Composer root/lock; re-plan and repair only the selected features.',
        );
    }

    /**
     * @param list<string> $features
     * @param list<Phase> $phases
     * @phpstan-return Outcome
     */
    private function outcome(
        string $operation,
        array $features,
        string $status,
        array $phases,
        ?string $recovery = null,
    ): array {
        return [
            'operation' => $operation,
            'features' => $features,
            'status' => $status,
            'phases' => $phases,
            'recovery' => $recovery,
        ];
    }

    /** @phpstan-return Phase */
    private function phase(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
