<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

/**
 * Canonical public CLI projection of the approved Foundation 4 taxonomy.
 * Read/plan/mutation commands must share this one source, not legacy aliases.
 */
final readonly class ModuleFeatureCommandCatalog
{
    private ModuleArchitectureCatalog $catalog;

    private ModuleFeatureResolver $resolver;

    public function __construct(
        ?ModuleArchitectureCatalog $catalog = null,
        ?ModuleFeatureResolver $resolver = null,
    ) {
        $this->catalog = $catalog ?? new ModuleArchitectureCatalog();
        $this->resolver = $resolver ?? new ModuleFeatureResolver($this->catalog);
    }

    /**
     * Stable machine contract. Core/QA have no entries and cannot be installed.
     *
     * @return array{schema_version:int,modules:list<array{name:string,description:string,features:list<string>}>}
     */
    public function inventory(): array
    {
        $rows = [];
        foreach ($this->catalog->all() as $module => $definition) {
            $rows[] = [
                'name' => $module,
                'description' => $definition['description'],
                'features' => array_map(
                    static fn(string $name): string => $module . '.' . $name,
                    array_keys($definition['features']),
                ),
            ];
        }

        return ['schema_version' => 2, 'modules' => $rows];
    }

    /**
     * Only a qualified ID or explicit group feature(s) is actionable.
     * A bare group cannot imply activation or installation of siblings.
     *
     * @param list<string> $features
     * @return list<string>
     */
    public function selection(string $module, array $features = []): array
    {
        if (str_contains($module, '.')) {
            if ($features !== []) {
                throw new \InvalidArgumentException('Qualified feature requests must not also supply --feature.');
            }

            $this->catalog->resolve($module);

            return [$module];
        }

        if (!isset($this->catalog->all()[$module])) {
            throw new \InvalidArgumentException(sprintf('Unknown optional module "%s".', $module));
        }

        if ($features === []) {
            throw new \InvalidArgumentException(sprintf(
                'Module "%s" has no implicit feature selection; specify module.feature or --feature.',
                $module,
            ));
        }

        $requested = [];
        foreach ($features as $feature) {
            $id = str_contains($feature, '.') ? $feature : $module . '.' . $feature;
            $definition = $this->catalog->resolve($id);
            if ($definition['module'] !== $module) {
                throw new \InvalidArgumentException(sprintf(
                    'Feature "%s" does not belong to module "%s".',
                    $id,
                    $module,
                ));
            }
            $requested[] = $id;
        }

        return $this->resolver->normalize($requested);
    }
}
