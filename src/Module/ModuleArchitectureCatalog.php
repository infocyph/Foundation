<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Module\Internal\ModuleArchitectureValidator;

/**
 * Fixed Foundation 4 optional feature taxonomy. Required Core libraries and
 * development-only PHPForge are intentionally not application modules.
 *
 * This is the target metadata for the staged module-lifecycle cutover.
 * Existing module commands still use ModuleCatalog::all() until the state
 * resolver, planner and mutation commands migrate together.
 *
 * @phpstan-type FeatureDependency array{target:string,when?:array{key:string,operator:'equals'|'not-empty',value?:bool|int|string}}
 * @phpstan-type ArchitectureFeature array{
 *     description:string,
 *     owner:string,
 *     packages:non-empty-array<string,string>,
 *     config:list<string>,
 *     schemas:list<string>,
 *     platform:array{extensions:list<string>,optional_extensions:list<string>},
 *     dependencies:list<FeatureDependency>,
 *     selection_key:?string
 * }
 * @phpstan-type ArchitectureModule array{
 *     description:string,
 *     features:array<string,ArchitectureFeature>
 * }
 */
final class ModuleArchitectureCatalog
{
    private const array FEATURE_IDS = [
        'data' => ['database', 'filesystem'],
        'security' => ['crypto', 'validation', 'mfa', 'passkey'],
        'execution' => ['runtime', 'messaging', 'communication'],
    ];

    /** @var array<string,ArchitectureModule> */
    private const array MODULES = [
        'data' => [
            'description' => 'Database persistence and filesystem storage.',
            'features' => [
                'database' => [
                    'description' => 'DBLayer connections, queries, migrations and persistence.',
                    'owner' => 'infocyph/dblayer',
                    'packages' => ['infocyph/dblayer' => '^6.1'],
                    'config' => ['database.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => ['pdo'], 'optional_extensions' => []],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
                'filesystem' => [
                    'description' => 'Pathwise-backed filesystem and storage operations.',
                    'owner' => 'infocyph/pathwise',
                    'packages' => ['infocyph/pathwise' => '^4.2'],
                    'config' => ['filesystem.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => ['fileinfo'], 'optional_extensions' => ['zip']],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
            ],
        ],
        'security' => [
            'description' => 'Optional native cryptography, request validation, MFA and passkeys.',
            'features' => [
                'crypto' => [
                    'description' => 'Epicrypt cryptography and token/key primitives.',
                    'owner' => 'infocyph/epicrypt',
                    'packages' => ['infocyph/epicrypt' => '^3.2'],
                    'config' => ['security.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => [], 'optional_extensions' => ['sodium']],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
                'validation' => [
                    'description' => 'ReqShield request and input validation.',
                    'owner' => 'infocyph/reqshield',
                    'packages' => ['infocyph/reqshield' => '^3.3'],
                    'config' => ['validation.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => ['fileinfo', 'hash', 'mbstring'], 'optional_extensions' => []],
                    'dependencies' => [
                        [
                            'target' => 'data.database',
                            'when' => ['key' => 'validation.database_connection', 'operator' => 'not-empty'],
                        ],
                    ],
                    'selection_key' => null,
                ],
                'mfa' => [
                    'description' => 'Selected OTP MFA factors and recovery.',
                    'owner' => 'infocyph/otp',
                    'packages' => ['infocyph/otp' => '^7.0'],
                    'config' => [],
                    'schemas' => [],
                    'platform' => ['extensions' => ['ctype'], 'optional_extensions' => ['sodium']],
                    'dependencies' => [],
                    'selection_key' => 'auth.drivers.mfa',
                ],
                'passkey' => [
                    'description' => 'Selected WebAuthn passkeys; shares OTP package without enabling MFA.',
                    'owner' => 'infocyph/otp',
                    'packages' => [
                        'infocyph/otp' => '^7.0',
                        'web-auth/webauthn-lib' => '^5.3.9',
                    ],
                    'config' => [],
                    'schemas' => [],
                    'platform' => ['extensions' => ['ctype'], 'optional_extensions' => ['sodium']],
                    'dependencies' => [],
                    'selection_key' => 'auth.drivers.passkey',
                ],
            ],
        ],
        'execution' => [
            'description' => 'Selected persistent runtime, messaging and communication.',
            'features' => [
                'runtime' => [
                    'description' => 'Runwire-hosted persistent runtime and native worker lifecycle.',
                    'owner' => 'infocyph/runwire',
                    'packages' => ['infocyph/runwire' => '^2.1.1'],
                    'config' => [],
                    'schemas' => [],
                    'platform' => ['extensions' => [], 'optional_extensions' => ['pcntl', 'posix']],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
                'messaging' => [
                    'description' => 'Omnibus events, queue transports and durable messaging.',
                    'owner' => 'infocyph/omnibus',
                    'packages' => ['infocyph/omnibus' => '^3.0'],
                    'config' => ['messaging.php'],
                    'schemas' => ['messaging'],
                    'platform' => ['extensions' => [], 'optional_extensions' => []],
                    'dependencies' => [
                        [
                            'target' => 'data.database',
                            'when' => ['key' => 'messaging.durable.enabled', 'operator' => 'equals', 'value' => true],
                        ],
                    ],
                    'selection_key' => null,
                ],
                'communication' => [
                    'description' => 'TalkingBytes HTTP, mail, webhook and gRPC integration.',
                    'owner' => 'infocyph/talkingbytes',
                    'packages' => ['infocyph/talkingbytes' => '^2.3'],
                    'config' => ['communication.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => [], 'optional_extensions' => []],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
            ],
        ],
    ];

    /** @phpstan-return array<string,ArchitectureModule> */
    public function all(): array
    {
        return self::MODULES;
    }

    /**
     * @phpstan-return ArchitectureFeature
     */
    public function feature(string $module, string $feature): array
    {
        $selected = self::MODULES[$module]['features'][$feature] ?? null;
        if ($selected === null) {
            throw new \InvalidArgumentException(sprintf('Unknown Foundation optional feature "%s.%s".', $module, $feature));
        }

        return $selected;
    }

    /**
     * Resolve only explicitly selected features; a bare module never installs
     * every sibling package.
     *
     * @return array<string,string>
     * @param list<string> $features
     */
    public function packages(string $module, array $features): array
    {
        if (!isset(self::MODULES[$module])) {
            throw new \InvalidArgumentException(sprintf('Unknown Foundation optional module "%s".', $module));
        }

        $packages = [];
        foreach ($features as $feature) {
            foreach ($this->feature($module, $feature)['packages'] as $package => $constraint) {
                if (isset($packages[$package]) && $packages[$package] !== $constraint) {
                    throw new \LogicException(sprintf('Incompatible shared package constraint for "%s".', $package));
                }
                $packages[$package] = $constraint;
            }
        }

        return $packages;
    }

    /**
     * Validate fixed module IDs, package ownership, dependency constraints,
     * schemas and platform metadata without altering application state.
     *
     * @phpstan-param array<string,ArchitectureModule>|null $modules
     */
    public function validate(?array $modules = null): void
    {
        new ModuleArchitectureValidator()->validate($modules ?? self::MODULES, self::FEATURE_IDS);
    }
}
