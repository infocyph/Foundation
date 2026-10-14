<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Module\ModuleFeatureCapabilityPolicy;
use Infocyph\Foundation\Support\ValueNormalizer;

/**
 * Mirrors FoundationBuildContext's explicit capability-selection semantics for
 * validation/readiness code that only receives a ConfigRepository.
 */
final readonly class ConfiguredCapabilities
{
    /** @var array<string,bool>|null */
    private ?array $featureSelections;

    public function __construct(private ConfigRepository $config)
    {
        $this->featureSelections = ModuleFeatureCapabilityPolicy::fromConfig($config);
    }

    public function enabled(string $capability): bool
    {
        return ModuleFeatureCapabilityPolicy::permits($capability, $this->featureSelections)
            && $this->legacyEnabled($capability);
    }

    public function explicit(?string $capability = null): bool
    {
        if ($capability !== null
            && $this->featureSelections !== null
            && ModuleFeatureCapabilityPolicy::owner($capability) !== null
        ) {
            return true;
        }

        if ($capability !== null) {
            return $this->config->has('app.capabilities')
                || $this->config->has('modules.capabilities.' . $capability);
        }

        return $this->config->has('app.capabilities')
            || is_array($this->config->get('modules.capabilities'));
    }

    private function legacyEnabled(string $capability): bool
    {
        if ($this->config->has('modules.capabilities.' . $capability)) {
            return ValueNormalizer::bool(
                $this->config->get('modules.capabilities.' . $capability),
                false,
            );
        }

        if (!$this->config->has('app.capabilities')) {
            return true;
        }

        $configured = $this->config->get('app.capabilities', []);
        if (!is_array($configured)) {
            return false;
        }

        foreach ($configured as $name => $enabled) {
            if (is_int($name)) {
                if (is_string($enabled) && $enabled === $capability) {
                    return true;
                }

                continue;
            }

            if ($name === $capability) {
                return ValueNormalizer::bool($enabled, false);
            }
        }

        return false;
    }
}
