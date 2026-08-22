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
            throw new BootstrapException(sprintf(
                'Provider file "%s" must return a grouped provider array.',
                $file,
            ));
        }
        if ($providers !== [] && array_is_list($providers)) {
            throw new BootstrapException(
                'Provider files must define common, web, cli, worker, and scheduler provider groups.',
            );
        }

        foreach ($providers as $group => $configured) {
            if (!is_string($group) || !in_array($group, self::GROUPS, true)) {
                throw new BootstrapException(sprintf(
                    'Provider file "%s" contains unsupported provider group "%s".',
                    $file,
                    is_scalar($group) ? (string) $group : get_debug_type($group),
                ));
            }
            if (!is_array($configured)) {
                throw new BootstrapException(sprintf(
                    'Provider group "%s" in "%s" must be a provider list.',
                    $group,
                    $file,
                ));
            }
        }

        foreach (self::GROUPS as $group) {
            foreach ($providers[$group] ?? [] as $index => $provider) {
                if (!is_string($provider) || trim($provider) === '') {
                    throw new BootstrapException(sprintf(
                        'Provider group "%s" entry %s in "%s" must be a non-empty provider class name.',
                        $group,
                        (string) $index,
                        $file,
                    ));
                }
                if (!class_exists($provider)) {
                    throw new BootstrapException(sprintf(
                        'Configured provider "%s" in group "%s" does not exist.',
                        $provider,
                        $group,
                    ));
                }
                if (!is_subclass_of($provider, ServiceProviderInterface::class)) {
                    throw new BootstrapException(sprintf(
                        'Configured provider "%s" in group "%s" must implement %s.',
                        $provider,
                        $group,
                        ServiceProviderInterface::class,
                    ));
                }

                /** @var class-string<ServiceProviderInterface> $provider */
                $resolved[$group][] = $provider;
            }

            $resolved[$group] = array_values(array_unique($resolved[$group]));
        }

        return $resolved;
    }

    /** @return list<class-string<ServiceProviderInterface>> */
    public function providers(RuntimeMode $runtimeMode): array
    {
        $groups = $this->groups();

        return array_values(array_unique([
            ...$groups['common'],
            ...$groups[$runtimeMode->value],
        ]));
    }
}
