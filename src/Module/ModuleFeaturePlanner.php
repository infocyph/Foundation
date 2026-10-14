<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

/**
 * Deterministic side-effect-free feature change plan. The caller supplies a
 * snapshot of Composer/root ownership and host configuration; no file writes.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 * @phpstan-import-type PackageState from ModuleFeatureResolver
 * @phpstan-import-type Resolution from ModuleFeatureResolver
 * @phpstan-import-type FeatureState from ModuleFeatureResolver
 * @phpstan-type FeaturePlan array{
 *     schema_version:2,operation:string,requested:list<string>,required:list<string>,
 *     states:array<string,FeatureState>,
 *     effects:array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>},
 *     blockers:list<string>,warnings:list<string>,applicable:bool
 * }
 */
final readonly class ModuleFeaturePlanner
{
    private ModuleArchitectureCatalog $catalog;

    private ModuleFeatureResolver $resolver;

    public function __construct(?ModuleFeatureResolver $resolver = null, ?ModuleArchitectureCatalog $catalog = null)
    {
        $this->resolver = $resolver ?? new ModuleFeatureResolver();
        $this->catalog = $catalog ?? new ModuleArchitectureCatalog();
    }

    /**
     * @param list<string> $features
     * @phpstan-param Context $context
     * @phpstan-return FeaturePlan
     */
    public function plan(string $operation, array $features, array $context): array
    {
        if (!in_array($operation, ['install', 'enable', 'disable', 'remove', 'repair'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown feature operation "%s".', $operation));
        }

        $requested = $this->resolver->normalize($features);
        if ($requested === []) {
            throw new \InvalidArgumentException('An explicit optional module feature is required.');
        }

        $required = $this->requirements($requested, $context);
        $snapshot = $this->resolver->resolve($required, $context);
        $blockers = in_array($operation, ['disable', 'remove'], true) ? [] : $snapshot['blockers'];
        $warnings = [];
        $effects = ['require' => [], 'remove' => [], 'activate' => [], 'publish' => [], 'schemas' => []];

        if (!$context['root_known']) {
            $blockers[] = 'Root Composer ownership is unknown; refusing feature mutation.';
        }

        foreach ($requested as $id) {
            $this->applyRequested($operation, $id, $requested, $snapshot, $context, $effects, $blockers);
        }

        if (in_array($operation, ['install', 'repair'], true)) {
            $this->planRequiredEffects($required, $snapshot, $context, $effects, $blockers, $warnings);
        } elseif ($operation === 'enable') {
            foreach (array_diff($required, $requested) as $id) {
                $this->checkEnable($id, $snapshot['states'][$id], $context, $blockers);
            }
        } elseif ($operation === 'remove') {
            $effects['remove'] = array_values(array_unique($effects['remove']));
        }

        $warnings = array_values(array_unique($warnings));
        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => 2,
            'operation' => $operation,
            'requested' => $requested,
            'required' => $required,
            'states' => $snapshot['states'],
            'effects' => $effects,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'applicable' => $blockers === [],
        ];
    }

    /**
     * @param list<string> $requested
     * @phpstan-param Resolution $snapshot
     * @phpstan-param Context $context
     * @param array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>} $effects
     * @param list<string> $blockers
     */
    private function applyRequested(
        string $operation,
        string $id,
        array $requested,
        array $snapshot,
        array $context,
        array &$effects,
        array &$blockers,
    ): void {
        $state = $snapshot['states'][$id];

        if ($operation === 'install' || $operation === 'repair') {
            return;
        }

        if ($operation === 'enable') {
            $this->checkEnable($id, $state, $context, $blockers);
            $effects['activate'][$id] = true;

            return;
        }

        if ($operation === 'disable') {
            $this->checkDependents($id, $context, $blockers);
            $effects['activate'][$id] = false;

            return;
        }

        $this->checkRemove($id, $requested, $state, $context, $effects, $blockers);
    }

    /**
     * @phpstan-param Context $context
     * @param list<string> $blockers
     */
    private function checkDependents(string $id, array $context, array &$blockers): void
    {
        $active = $this->resolver->resolve($context['enabled'], $context)['states'];

        foreach ($active as $dependent => $state) {
            if ($dependent !== $id && in_array($id, $state['dependencies'], true)) {
                $blockers[] = sprintf('Feature %s still depends on %s.', $dependent, $id);
            }
        }
    }

    /**
     * @phpstan-param FeatureState $state
     * @phpstan-param Context $context
     * @param list<string> $blockers
     */
    private function checkEnable(
        string $id,
        array $state,
        array $context,
        array &$blockers,
    ): void {
        if (!$state['allowed']) {
            $blockers[] = sprintf('Production capability policy does not allow %s.', $id);
        }

        foreach ($state['packages'] as $package => $packageState) {
            if ($packageState['version'] === null) {
                $blockers[] = sprintf('Install required package %s before enabling %s.', $package, $id);
            }
        }

        foreach ($state['missing_extensions'] as $extension) {
            $blockers[] = sprintf('Extension %s is required for %s.', $extension, $id);
        }

        foreach ($state['dependencies'] as $dependency) {
            if (!in_array($dependency, $context['enabled'], true)) {
                $blockers[] = sprintf('Enable required feature %s before %s.', $dependency, $id);
            }
        }
    }

    /**
     * @param list<string> $requested
     * @phpstan-param FeatureState $state
     * @phpstan-param Context $context
     * @param array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>} $effects
     * @param list<string> $blockers
     */
    private function checkRemove(
        string $id,
        array $requested,
        array $state,
        array $context,
        array &$effects,
        array &$blockers,
    ): void {
        $this->checkDependents($id, $context, $blockers);

        if ($state['enabled']) {
            $blockers[] = sprintf('Disable feature %s before removal.', $id);
        }

        $other = array_values(array_diff(
            array_unique([...$context['enabled'], ...($context['selected_features'] ?? [])]),
            $requested,
        ));
        $otherRequired = $this->requirements($other, $context);
        $usedPackages = [];

        foreach ($otherRequired as $feature) {
            foreach ($this->catalog->resolve($feature)['definition']['packages'] as $package => $constraint) {
                $usedPackages[$package] = true;
            }
        }

        foreach ($state['packages'] as $package => $packageState) {
            if ($packageState['direct'] && !isset($usedPackages[$package])) {
                $effects['remove'][] = $package;
            }
        }
    }

    /**
     * @param list<string> $required
     * @phpstan-param Resolution $snapshot
     * @phpstan-param Context $context
     * @param array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>} $effects
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private function planRequiredEffects(
        array $required,
        array $snapshot,
        array $context,
        array &$effects,
        array &$blockers,
        array &$warnings,
    ): void {
        foreach ($required as $id) {
            $state = $snapshot['states'][$id];
            foreach ($state['missing_extensions'] as $extension) {
                $blockers[] = sprintf('Extension %s is required for %s.', $extension, $id);
            }
            $this->planAssets($state, $context, $effects);
            $this->planPackages($state['packages'], $effects, $warnings);
        }
    }

    /**
     * @phpstan-param FeatureState $state
     * @phpstan-param Context $context
     * @param array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>} $effects
     */
    private function planAssets(array $state, array $context, array &$effects): void
    {
        foreach ($state['config'] as $filename) {
            if (!in_array($filename, $context['published_configs'] ?? [], true)
                && !in_array($filename, $effects['publish'], true)
            ) {
                $effects['publish'][] = $filename;
            }
        }

        foreach ($state['schemas'] as $schema) {
            if (!in_array($schema, $effects['schemas'], true)) {
                $effects['schemas'][] = $schema;
            }
        }
    }

    /**
     * @phpstan-param array<string,PackageState> $packages
     * @param array{require:array<string,string>,remove:list<string>,activate:array<string,bool>,publish:list<string>,schemas:list<string>} $effects
     * @param list<string> $warnings
     */
    private function planPackages(array $packages, array &$effects, array &$warnings): void
    {
        foreach ($packages as $package => $state) {
            if ($state['direct']) {
                continue;
            }

            $effects['require'][$package] = $state['constraint'];

            if ($state['transitive']) {
                $warnings[] = sprintf('Package %s is available transitively; make ownership direct.', $package);
            }
        }
    }

    /**
     * @param list<string> $requested
     * @phpstan-param Context $context
     * @return list<string>
     */
    private function requirements(array $requested, array $context): array
    {
        $required = $requested;
        $queue = $requested;

        while ($queue !== []) {
            $id = array_shift($queue);
            $one = $this->resolver->resolve([$id], $context)['states'][$id];

            foreach ($one['dependencies'] as $dependency) {
                if (!in_array($dependency, $required, true)) {
                    $required[] = $dependency;
                    $queue[] = $dependency;
                }
            }
        }

        return $this->resolver->normalize($required);
    }
}
