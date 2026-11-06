<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\ArrayKit\Config\Config;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\PathManager;

final readonly class ProviderFileLoader
{
    private const array GROUPS = ['common', 'web', 'cli', 'worker', 'scheduler'];

    private PathManager $paths;

    public function __construct(PathManager $paths)
    {
        $this->paths = $paths;
    }

    /**
     * @return array{common:list<class-string<ServiceProviderInterface>>,web:list<class-string<ServiceProviderInterface>>,cli:list<class-string<ServiceProviderInterface>>,worker:list<class-string<ServiceProviderInterface>>,scheduler:list<class-string<ServiceProviderInterface>>}
     * @throws \Infocyph\Foundation\Exception\BootstrapException
     */
    public function groups(): array
    {
        $resolved = array_fill_keys(self::GROUPS, []);
        $file = $this->paths->providersFile();
        if (!is_file($file)) {
            return $resolved;
        }

        $providers = $this->providerConfig($file);
        foreach ($providers as $group => $configured) {
            $this->assertGroup($file, $group, $configured);
        }

        foreach (self::GROUPS as $group) {
            $resolved[$group] = $this->providersForGroup($file, $group, $providers[$group] ?? []);
        }

        return $resolved;
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     * @throws BootstrapException
     */
    public function providers(RuntimeMode $runtimeMode): array
    {
        $groups = $this->groups();

        return array_values(array_unique([
            ...$groups['common'],
            ...$groups[$runtimeMode->value],
        ]));
    }

    /**
     * @throws \Infocyph\Foundation\Exception\BootstrapException
     */
    private function assertGroup(string $file, int|string $group, mixed $configured): void
    {
        if (!is_string($group) || !in_array($group, self::GROUPS, true)) {
            throw new BootstrapException(sprintf(
                'Provider file "%s" contains unsupported provider group "%s".',
                $file,
                (string) $group,
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

    /**
     * @return array<array-key, mixed>
     * @throws \Infocyph\Foundation\Exception\BootstrapException
     */
    private function providerConfig(string $file): array
    {
        $source = new Config();
        try {
            if (!$source->loadFile($file)) {
                throw new BootstrapException(sprintf('Provider file "%s" is not readable.', $file));
            }
        } catch (\UnexpectedValueException $exception) {
            throw new BootstrapException(
                sprintf('Provider file "%s" must return a grouped provider array.', $file),
                previous: $exception,
            );
        }

        $providers = $source->all();
        if ($providers !== [] && array_is_list($providers)) {
            throw new BootstrapException(
                'Provider files must define common, web, cli, worker, and scheduler provider groups.',
            );
        }

        return $providers;
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     * @throws BootstrapException
     */
    private function providersForGroup(string $file, string $group, mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $resolved = [];
        foreach ($configured as $index => $provider) {
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

            $resolved[] = $provider;
        }

        return array_values(array_unique($resolved));
    }
}
