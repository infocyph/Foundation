<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\PathManager;

final readonly class ProviderFileLoader
{
    private const array GROUPS = ['common', 'web', 'cli', 'worker', 'scheduler'];

    public function __construct(private PathManager $paths) {}

    /**
     * @return array{common:list<class-string<ServiceProviderInterface>>,web:list<class-string<ServiceProviderInterface>>,cli:list<class-string<ServiceProviderInterface>>,worker:list<class-string<ServiceProviderInterface>>,scheduler:list<class-string<ServiceProviderInterface>>}
     */
    public function groups(): array
    {
        $resolved = array_fill_keys(self::GROUPS, []);
        $file = $this->paths->providersFile();
        if (!is_file($file)) {
            return $resolved;
        }

        $providers = require $file;
        if (!is_array($providers)) {
            return $resolved;
        }
        if ($providers !== [] && array_is_list($providers)) {
            throw new BootstrapException(
                'Provider files must define common, web, cli, worker, and scheduler provider groups.',
            );
        }

        foreach (self::GROUPS as $group) {
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

    /** @return list<class-string<ServiceProviderInterface>> */
    public function providers(RuntimeMode $runtimeMode): array
    {
        $groups = $this->groups();

        return [...$groups['common'], ...$groups[$runtimeMode->value]];
    }
}
