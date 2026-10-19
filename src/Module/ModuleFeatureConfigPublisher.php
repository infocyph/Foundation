<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Module\Internal\ModuleConfigPublisher;

/**
 * Feature-owned config phase. Reuses the existing atomic project-safe
 * publisher and never force-overwrites application-owned configuration.
 *
 * @phpstan-import-type Context from ModuleFeatureResolver
 */
final readonly class ModuleFeatureConfigPublisher
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
     * @return array{planned:list<string>,published:list<string>,existing:list<string>}
     */
    public function publish(array $features, array $context, bool $dryRun = false): array
    {
        $plan = $this->planner->plan('install', $features, $context);
        if (!$plan['applicable']) {
            throw new \RuntimeException('Feature config publication is blocked: ' . implode('; ', $plan['blockers']));
        }

        $configured = $plan['effects']['publish'];
        if ($configured === [] || $dryRun) {
            return ['planned' => $configured, 'published' => [], 'existing' => []];
        }

        $result = new ModuleConfigPublisher($this->application)->publish($configured, false);
        if ($result['published'] !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return ['planned' => $configured, ...$result];
    }
}
