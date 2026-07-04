<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Filesystem\PathManager;

final readonly class ProviderFileLoader
{
    public function __construct(
        private PathManager $paths,
    ) {}

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(): array
    {
        $file = $this->paths->providersFile();

        if (!is_file($file)) {
            return [];
        }

        $providers = require $file;
        if (!is_array($providers)) {
            return [];
        }

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
}
