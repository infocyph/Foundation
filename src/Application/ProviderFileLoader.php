<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\PathManager;

final readonly class ProviderFileLoader
{
    public function __construct(
        private PathManager $paths,
    ) {}

    /**
     * @param RuntimeMode $runtimeMode Runtime whose provider groups are selected.
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(RuntimeMode $runtimeMode): array
    {
        $file = $this->paths->providersFile();

        if (!is_file($file)) {
            return [];
        }

        $providers = require $file;
        if (!is_array($providers)) {
            return [];
        }

        $providers = $this->forRuntime($providers, $runtimeMode);
        $resolved = [];

        foreach ($providers as $provider) {
            if (!is_string($provider) || !class_exists($provider)) {
                continue;
            }

            if (!is_subclass_of($provider, ServiceProviderInterface::class)) {
                continue;
            }

            $resolved[] = $provider;
        }

        return $resolved;
    }

    /**
     * @param array<array-key, mixed> $providers
     * @return list<mixed>
     */
    private function forRuntime(array $providers, RuntimeMode $runtimeMode): array
    {
        if ($providers !== [] && array_is_list($providers)) {
            throw new BootstrapException(
                'Provider files must define common, web, and console provider groups.',
            );
        }

        $selected = [];

        foreach (['common', $runtimeMode->value] as $group) {
            $configured = $providers[$group] ?? [];
            if (!is_array($configured)) {
                continue;
            }

            foreach ($configured as $provider) {
                $selected[] = $provider;
            }
        }

        return $selected;
    }
}
