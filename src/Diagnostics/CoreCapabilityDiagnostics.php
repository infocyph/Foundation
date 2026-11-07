<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;

/**
 * Built-in Foundation services are never installable optional modules.
 * This fixed inventory is readable without Composer, package probing, or
 * discovery on a runtime hot path.
 */
final class CoreCapabilityDiagnostics
{
    /** @var list<string> */
    private const array CORE = [
        'auth',
        'cache',
        'logging',
        'operations',
        'resources',
        'runwire',
        'session',
        'notifications',
    ];

    /** @return array<string,array{ready:bool,detail:string}> */
    public function checks(ConfiguredCapabilities $policy): array
    {
        $checks = [];
        foreach (self::CORE as $capability) {
            $enabled = in_array($capability, ['logging', 'operations', 'resources'], true)
                || $policy->enabled($capability);
            $checks['core:' . $capability] = [
                'ready' => true,
                'detail' => $enabled
                    ? 'prebuilt Foundation Core; available by policy; not installable as a module'
                    : 'prebuilt Foundation Core; disabled by application policy; not installable as a module',
            ];
        }

        return $checks;
    }
}
