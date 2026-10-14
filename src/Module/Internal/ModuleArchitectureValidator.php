<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

/**
 * Keep architecture validation outside the hot catalog reader. The fixed
 * module feature inventory is intentionally not assembled by discovery.
 *
 * @phpstan-import-type ArchitectureFeature from \Infocyph\Foundation\Module\ModuleArchitectureCatalog
 * @phpstan-import-type ArchitectureModule from \Infocyph\Foundation\Module\ModuleArchitectureCatalog
 */
final class ModuleArchitectureValidator
{
    private const array CORE_OR_DEVELOPMENT_PACKAGES = [
        'infocyph/arraykit',
        'infocyph/cachelayer',
        'infocyph/intermix',
        'infocyph/uid',
        'infocyph/webrick',
        'infocyph/phpforge',
        'psr/log',
        'opis/closure',
    ];

    /**
     * @phpstan-param array<string,ArchitectureModule> $modules
     * @param array<string,list<string>> $expected
     */
    public function validate(array $modules, array $expected): void
    {
        if (array_keys($modules) !== array_keys($expected)) {
            throw new \LogicException('Foundation optional catalog must contain only data, security and execution.');
        }

        $packageOwners = [];
        $schemaOwners = [];
        $edges = [];
        foreach ($expected as $module => $featureIds) {
            $features = $modules[$module]['features'];
            if (trim($modules[$module]['description']) === '' || array_keys($features) !== $featureIds) {
                throw new \LogicException(sprintf('Invalid or reordered features on module "%s".', $module));
            }

            foreach ($features as $name => $definition) {
                $id = $module . '.' . $name;
                $this->assertFeature($id, $definition, $modules, $packageOwners, $schemaOwners, $edges);
            }
        }

        $this->assertAcyclic($edges);
    }

    /** @param array<string,list<string>> $edges */
    private function assertAcyclic(array $edges): void
    {
        $visiting = [];
        $visited = [];
        $visit = static function (string $node) use (&$visit, &$visiting, &$visited, $edges): void {
            if (isset($visited[$node])) {
                return;
            }
            if (isset($visiting[$node])) {
                throw new \LogicException(sprintf('Cyclic optional feature dependencies at "%s".', $node));
            }
            $visiting[$node] = true;
            foreach ($edges[$node] ?? [] as $next) {
                $visit($next);
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($edges) as $node) {
            $visit($node);
        }
    }

    /** @param array{key:string,operator:'equals'|'not-empty',value?:bool|int|string}|null $when */
    private function assertCondition(string $id, ?array $when): void
    {
        if ($when === null) {
            return;
        }
        if ($when['key'] === '' || ($when['operator'] === 'equals' && !array_key_exists('value', $when))) {
            throw new \LogicException(sprintf('Invalid conditional dependency on "%s".', $id));
        }
    }

    /** @param list<string> $configs */
    private function assertConfiguration(string $id, array $configs): void
    {
        foreach ($configs as $config) {
            if (preg_match('/^[a-z][a-z0-9_]*\.php$/D', $config) !== 1) {
                throw new \LogicException(sprintf('Invalid config ownership path on "%s".', $id));
            }
        }
    }

    /**
     * @phpstan-param ArchitectureFeature $definition
     * @phpstan-param array<string,ArchitectureModule> $modules
     * @param array<string,list<array{id:string,constraint:string}>> $packageOwners
     * @param array<string,string> $schemaOwners
     * @param array<string,list<string>> $edges
     */
    private function assertFeature(
        string $id,
        array $definition,
        array $modules,
        array &$packageOwners,
        array &$schemaOwners,
        array &$edges,
    ): void {
        if (trim($definition['description']) === '' || !isset($definition['packages'][$definition['owner']])) {
            throw new \LogicException(sprintf('Optional feature "%s" has no declared native owner.', $id));
        }

        $this->assertPackages($id, $definition['packages'], $packageOwners);
        $this->assertSelectionKey($id, $definition['selection_key']);
        $this->assertPlatform($id, $definition['platform']);
        $this->assertConfiguration($id, $definition['config']);
        $this->assertSchemas($id, $definition['schemas'], $schemaOwners);
        $edges[$id] = $this->resolveDependencies($id, $definition['dependencies'], $modules);
    }

    private function assertPackageRole(string $id, string $package, string $constraint): void
    {
        if ($package === '' || $constraint === '' || in_array($package, self::CORE_OR_DEVELOPMENT_PACKAGES, true)) {
            throw new \LogicException(sprintf('Invalid optional package owner "%s" on "%s".', $package, $id));
        }
        if ($package === 'infocyph/otp' && !in_array($id, ['security.mfa', 'security.passkey'], true)) {
            throw new \LogicException('OTP shared ownership is limited to MFA and passkey.');
        }
    }

    /**
     * @param array<string,string> $packages
     * @param array<string,list<array{id:string,constraint:string}>> $packageOwners
     */
    private function assertPackages(string $id, array $packages, array &$packageOwners): void
    {
        foreach ($packages as $package => $constraint) {
            $this->assertPackageRole($id, $package, $constraint);
            $owners = $packageOwners[$package] ?? [];
            $this->assertPackageSharing($id, $package, $constraint, $owners);
            $packageOwners[$package][] = ['id' => $id, 'constraint' => $constraint];
        }
    }

    /** @param list<array{id:string,constraint:string}> $owners */
    private function assertPackageSharing(string $id, string $package, string $constraint, array $owners): void
    {
        if ($owners !== [] && $package !== 'infocyph/otp') {
            throw new \LogicException(sprintf('Package "%s" has multiple unrelated feature owners.', $package));
        }
        if ($owners !== [] && $owners[0]['constraint'] !== $constraint) {
            throw new \LogicException(sprintf('Conflicting shared constraints for "%s" at "%s".', $package, $id));
        }
    }

    /** @param array{extensions:list<string>,optional_extensions:list<string>} $platform */
    private function assertPlatform(string $id, array $platform): void
    {
        foreach (['extensions', 'optional_extensions'] as $type) {
            foreach ($platform[$type] as $extension) {
                if (preg_match('/^[a-z][a-z0-9_]*$/D', $extension) !== 1) {
                    throw new \LogicException(sprintf('Invalid platform extension on "%s".', $id));
                }
            }
        }
    }

    /**
     * @param list<string> $schemas
     * @param array<string,string> $owners
     */
    private function assertSchemas(string $id, array $schemas, array &$owners): void
    {
        foreach ($schemas as $schema) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $schema) !== 1 || isset($owners[$schema])) {
                throw new \LogicException(sprintf('Invalid or duplicate schema owner on "%s".', $id));
            }
            $owners[$schema] = $id;
        }
    }

    private function assertSelectionKey(string $id, ?string $key): void
    {
        if ($key !== null && !in_array($id, ['security.mfa', 'security.passkey'], true)) {
            throw new \LogicException(sprintf('Unexpected selected authentication driver on "%s".', $id));
        }
    }

    /**
     * @phpstan-param list<array{target:string,when?:array{key:string,operator:'equals'|'not-empty',value?:bool|int|string}}> $dependencies
     * @phpstan-param array<string,ArchitectureModule> $modules
     * @return list<string>
     */
    private function resolveDependencies(string $id, array $dependencies, array $modules): array
    {
        $edges = [];
        foreach ($dependencies as $dependency) {
            $target = $dependency['target'];
            [$module, $feature] = array_pad(explode('.', $target, 3), 3, null);
            if (!is_string($module) || !is_string($feature) || !isset($modules[$module]['features'][$feature])
                || $target === $id || substr_count($target, '.') !== 1
            ) {
                throw new \LogicException(sprintf('Unknown or self-referential feature dependency "%s" on "%s".', $target, $id));
            }
            $this->assertCondition($id, $dependency['when'] ?? null);
            $edges[] = $target;
        }

        return $edges;
    }
}
