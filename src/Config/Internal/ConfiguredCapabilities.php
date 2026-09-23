<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;

/**
 * Mirrors FoundationBuildContext's explicit capability-selection semantics for
 * validation/readiness code that only receives a ConfigRepository.
 */
final readonly class ConfiguredCapabilities
{
    public function __construct(private ConfigRepository $config) {}

    public function enabled(string $capability): bool
    {
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

    public function explicit(): bool
    {
        return $this->config->has('app.capabilities');
    }
}
