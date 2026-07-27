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
     * @return array{common:list<class-string<ServiceProviderInterface>>,web:list<class-string<ServiceProviderInterface>>,console:list<class-string<ServiceProviderInterface>>}
     */
    public function groups(): array
    {
        $empty = ['common' => [], 'web' => [], 'console' => []];
        $file = $this->paths->providersFile();
        if (!is_file($file)) {
            return $empty;
        }

        $providers = require $file;
        if (!is_array($providers)) {
            return $empty;
        }
        if ($providers !== [] && array_is_list($providers)) {
            throw new BootstrapException(
                'Provider files must define common, web, and console provider groups.',
            );
        }

        $resolved = $empty;
        foreach ($resolved as $group => $_providers) {
            $configured = $providers[$group] ?? [];
            if (!is_array($configured)) {
                continue;
            }

            foreach ($configured as $provider) {
                if (!is_string($provider)
                    || !class_exists($provider)
                    || !is_subclass_of($provider, ServiceProviderInterface::class)
                ) {
                    continue;
                }

                $resolved[$group][] = $provider;
            }
        }

        return $resolved;
    }

    /**
     * @param RuntimeMode $runtimeMode Runtime whose provider groups are selected.
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function providers(RuntimeMode $runtimeMode): array
    {
        $providers = $this->forRuntime($this->groups(), $runtimeMode);
        $resolved = [];

        foreach ($providers as $provider) {
            $resolved[] = $provider;
        }

        return $resolved;
    }

    /**
     * @param array{
     *     common:list<class-string<ServiceProviderInterface>>,
     *     web:list<class-string<ServiceProviderInterface>>,
     *     console:list<class-string<ServiceProviderInterface>>
     * } $providers
     * @return list<class-string<ServiceProviderInterface>>
     */
    private function forRuntime(array $providers, RuntimeMode $runtimeMode): array
    {
        $selected = [];

        foreach (['common', $runtimeMode->value] as $group) {
            foreach ($providers[$group] as $provider) {
                $selected[] = $provider;
            }
        }

        return $selected;
    }
}
