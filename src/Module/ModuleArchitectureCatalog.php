<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Module\Internal\ModuleArchitectureValidator;

/**
 * Fixed Foundation 4 optional feature taxonomy. Required Core libraries and
 * development-only PHPForge are intentionally not application modules.
 *
 * Authoritative three-module/eight-feature metadata for the Foundation 4
 * public schema-v2 module CLI, planner and lifecycle integration.
 * Core and QA never become installable optional modules.
 *
 * @psalm-type FeatureDependency = array{target:string,when?:array{key:string,operator:'equals'|'not-empty',value?:bool|int|string}}
 * @psalm-type ArchitectureFeature = array{
 *     description:string,
 *     owner:string,
 *     packages:non-empty-array<string,string>,
 *     config:list<string>,
 *     schemas:list<string>,
 *     platform:array{extensions:list<string>,optional_extensions:list<string>},
 *     dependencies:list<FeatureDependency>,
 *     selection_key:?string
 * }
 * @psalm-type ArchitectureModule = array{
 *     description:string,
 *     features:array<string,ArchitectureFeature>
 * }
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
 *
 * @api
 */
final class ModuleArchitectureCatalog
{
    /** @var non-empty-array<string,string> */
    private const array FEATURE_COMMUNICATION_PACKAGES = ['infocyph/talkingbytes' => '^2.3.1'];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_CRYPTO_PACKAGES = ['infocyph/epicrypt' => '^3.3'];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_DATABASE_PACKAGES = ['infocyph/dblayer' => '^6.1.4'];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_FILESYSTEM_PACKAGES = ['infocyph/pathwise' => '^4.2.1'];

    private const array FEATURE_IDS = [
        'data' => ['database', 'filesystem'],
        'security' => ['crypto', 'validation', 'mfa', 'passkey'],
        'execution' => ['messaging', 'communication'],
    ];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_MESSAGING_PACKAGES = ['infocyph/omnibus' => '^3.0.3'];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_MFA_PACKAGES = ['infocyph/otp' => '^7.0.1'];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_PASSKEY_PACKAGES = [
        'infocyph/otp' => '^7.0.1',
        'web-auth/webauthn-lib' => '^5.3.12',
    ];

    /** @var non-empty-array<string,string> */
    private const array FEATURE_VALIDATION_PACKAGES = ['infocyph/reqshield' => '^3.3.1'];

    /** @var array<string,ArchitectureModule> */
    private const array MODULES = [
        'data' => [
            'description' => 'Database persistence and filesystem storage.',
            'features' => [
                'database' => [
                    'description' => 'DBLayer connections, queries, migrations and persistence.',
                    'owner' => 'infocyph/dblayer',
                    'packages' => self::FEATURE_DATABASE_PACKAGES,
                    'config' => ['database.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => ['pdo'], 'optional_extensions' => []],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
                'filesystem' => [
                    'description' => 'Pathwise-backed filesystem and storage operations.',
                    'owner' => 'infocyph/pathwise',
                    'packages' => self::FEATURE_FILESYSTEM_PACKAGES,
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
                    'packages' => self::FEATURE_CRYPTO_PACKAGES,
                    'config' => ['security.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => [], 'optional_extensions' => ['sodium']],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
                'validation' => [
                    'description' => 'ReqShield request and input validation.',
                    'owner' => 'infocyph/reqshield',
                    'packages' => self::FEATURE_VALIDATION_PACKAGES,
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
                    'packages' => self::FEATURE_MFA_PACKAGES,
                    'config' => [],
                    'schemas' => [],
                    'platform' => ['extensions' => ['ctype'], 'optional_extensions' => ['sodium']],
                    'dependencies' => [
                        [
                            'target' => 'security.crypto',
                            'when' => ['key' => 'auth.drivers.mfa', 'operator' => 'equals', 'value' => 'otp'],
                        ],
                    ],
                    'selection_key' => 'auth.drivers.mfa',
                ],
                'passkey' => [
                    'description' => 'Selected WebAuthn passkeys; shares OTP package without enabling MFA.',
                    'owner' => 'infocyph/otp',
                    'packages' => self::FEATURE_PASSKEY_PACKAGES,
                    'config' => [],
                    'schemas' => [],
                    'platform' => ['extensions' => ['ctype'], 'optional_extensions' => ['sodium']],
                    'dependencies' => [],
                    'selection_key' => 'auth.drivers.passkey',
                ],
            ],
        ],
        'execution' => [
            'description' => 'Messaging and communication.',
            'features' => [
                'messaging' => [
                    'description' => 'Omnibus events, queue transports and durable messaging.',
                    'owner' => 'infocyph/omnibus',
                    'packages' => self::FEATURE_MESSAGING_PACKAGES,
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
                    'packages' => self::FEATURE_COMMUNICATION_PACKAGES,
                    'config' => ['communication.php'],
                    'schemas' => [],
                    'platform' => ['extensions' => [], 'optional_extensions' => []],
                    'dependencies' => [],
                    'selection_key' => null,
                ],
            ],
        ],
    ];

    /**
     * @phpstan-return array<string,ArchitectureModule>
     * @psalm-return array<string,ArchitectureModule>
     */
    public function all(): array
    {
        return self::MODULES;
    }

    /**
     * @phpstan-return ArchitectureFeature
     * @psalm-return ArchitectureFeature
     * @throws \InvalidArgumentException
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
     * @throws \InvalidArgumentException
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
     * Public selection is fully qualified; bare "runtime" is deliberately
     * invalid because it is also a historical Core operations alias.
     *
     * @phpstan-return array{module:string,feature:string,definition:ArchitectureFeature}
     * @psalm-return array{module:string,feature:string,definition:ArchitectureFeature}
     * @throws \InvalidArgumentException
     */
    public function resolve(string $id): array
    {
        $segments = explode('.', $id);
        if (count($segments) !== 2) {
            throw new \InvalidArgumentException(sprintf('Feature "%s" must use module.feature.', $id));
        }

        return [
            'module' => $segments[0],
            'feature' => $segments[1],
            'definition' => $this->feature($segments[0], $segments[1]),
        ];
    }

    /**
     * Validate fixed module IDs, package ownership, dependency constraints,
     * schemas and platform metadata without altering application state.
     *
     * @phpstan-param array<string,ArchitectureModule>|null $modules
     * @psalm-param array<string,ArchitectureModule>|null $modules
     * @throws \LogicException
     */
    public function validate(?array $modules = null): void
    {
        new ModuleArchitectureValidator()->validate($modules ?? self::MODULES, self::FEATURE_IDS);
    }
}
