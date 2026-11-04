<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Module\Internal\ModuleConfigPublisher;

/**
 * Core introspection and non-destructive configuration publication only.
 *
 * @phpstan-import-type ModuleState from ModuleStateResolver
 */
final readonly class ModuleManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
    ) {}

    /** @phpstan-return list<ModuleState> */
    public function all(): array
    {
        return new ModuleStateResolver($this->application, $this->catalog)->all();
    }

    /** @return array{published:list<string>,existing:list<string>} */
    public function publishConfig(string $module, bool $force = false): array
    {
        $configured = $this->catalog->resolve($module)['config'];
        $result = new ModuleConfigPublisher($this->application)->publish($configured, $force);
        if ($result['published'] !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return $result;
    }
}
