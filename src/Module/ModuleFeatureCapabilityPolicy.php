<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Config\ConfigRepository;

/**
 * Compile-time-only policy for the staged 3-module/9-feature topology.
 * It constrains legacy capability allowlists; it cannot grant privileges.
 * Core auth/cache/logging/session/notifications remain independently owned.
 */
final class ModuleFeatureCapabilityPolicy
{
    /** @var array<string,string> */
    private const array CAPABILITY_OWNERS = [
        'communication' => 'execution.communication',
        'database' => 'data.database',
        'filesystem' => 'data.filesystem',
        'messaging' => 'execution.messaging',
        'otp' => 'security.mfa',
        'passkeys' => 'security.passkey',
        'runwire' => 'execution.runtime',
        'security' => 'security.crypto',
        'validation' => 'security.validation',
    ];

    /**
     * A missing map retains legacy behavior until Batch 6 public cutover.
     * A present map is an explicit selection, including an empty map.
     *
     * @return array<string,bool>|null
     */
    public static function fromConfig(ConfigRepository $config): ?array
    {
        if (!$config->has('modules.features')) {
            return null;
        }

        $configured = $config->get('modules.features');
        if (!is_array($configured) || ($configured !== [] && array_is_list($configured))) {
            throw new \UnexpectedValueException('modules.features must be a keyed feature-to-boolean map.');
        }

        $catalog = new ModuleArchitectureCatalog();
        $selected = [];
        foreach ($configured as $feature => $enabled) {
            if (!is_string($feature) || !is_bool($enabled)) {
                throw new \UnexpectedValueException('modules.features must contain boolean feature flags.');
            }

            $catalog->resolve($feature);
            $selected[$feature] = $enabled;
        }

        ksort($selected);
        self::assertSelectedDependencies($selected, $config, $catalog);

        return $selected;
    }

    public static function owner(string $capability): ?string
    {
        return self::CAPABILITY_OWNERS[$capability] ?? null;
    }

    /** @param array<string,bool>|null $selections */
    public static function permits(string $capability, ?array $selections): bool
    {
        $owner = self::owner($capability);
        if ($owner === null || $selections === null) {
            return true;
        }

        return $selections[$owner] ?? false;
    }

    /**
     * @param array<string,bool>|null $selections
     */
    public static function selected(string $feature, ?array $selections): bool
    {
        new ModuleArchitectureCatalog()->resolve($feature);

        return $selections[$feature] ?? false;
    }

    /**
     * @param array<string,bool> $selected
     */
    private static function assertSelectedDependencies(
        array $selected,
        ConfigRepository $config,
        ModuleArchitectureCatalog $catalog,
    ): void {
        foreach ($selected as $feature => $enabled) {
            if (!$enabled) {
                continue;
            }
            foreach ($catalog->resolve($feature)['definition']['dependencies'] as $dependency) {
                $when = $dependency['when'] ?? null;
                if ($when !== null && !self::condition($config, $when)) {
                    continue;
                }
                if (!($selected[$dependency['target']] ?? false)) {
                    throw new \LogicException(sprintf(
                        'Selected feature %s requires active dependency %s.',
                        $feature,
                        $dependency['target'],
                    ));
                }
            }
        }
    }

    /**
     * @param array{key:string,operator:'equals'|'not-empty',value?:bool|int|string} $when
     */
    private static function condition(ConfigRepository $config, array $when): bool
    {
        $value = $config->get($when['key']);

        return $when['operator'] === 'equals'
            ? $value === ($when['value'] ?? null)
            : (is_string($value) ? trim($value) !== '' : $value !== null && $value !== false && $value !== []);
    }
}
