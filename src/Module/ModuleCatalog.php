<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Module\Internal\ModuleCatalogValidator;

/**
 * @phpstan-type ConfigPredicate array{
 *     key:string,
 *     operator:'equals'|'not-empty',
 *     value?:bool|int|string|null
 * }
 * @phpstan-type ModuleDependency array{
 *     type:'module'|'capability',
 *     target:string,
 *     reason:string,
 *     when?:ConfigPredicate
 * }
 * @phpstan-type PlatformRequirement array{
 *     extensions:list<string>,
 *     optional_extensions:list<string>,
 *     packages:list<string>
 * }
 * @phpstan-type ModuleFeature array{
 *     description:string,
 *     aliases:list<string>,
 *     dependencies:list<ModuleDependency>,
 *     platform:PlatformRequirement
 * }
 * @phpstan-type PackageRequirement array{
 *     constraint:?string,
 *     role:'required'|'feature'|'optional',
 *     features:list<string>
 * }
 * @phpstan-type ModuleDefinition array{
 *     packages:array<string,PackageRequirement>,
 *     built_in?:bool,
 *     description:string,
 *     aliases:list<string>,
 *     config:list<string>,
 *     schemas:list<string>,
 *     features:array<string,ModuleFeature>,
 *     dependencies:list<ModuleDependency>,
 *     platform:PlatformRequirement
 * }
 * @phpstan-type ResolvedModule array{
 *     name:string,
 *     packages:array<string,PackageRequirement>,
 *     built_in?:bool,
 *     description:string,
 *     aliases:list<string>,
 *     config:list<string>,
 *     schemas:list<string>,
 *     features:array<string,ModuleFeature>,
 *     dependencies:list<ModuleDependency>,
 *     platform:PlatformRequirement
 * }
 */
final class ModuleCatalog
{
    /** @var array<string, ModuleDefinition> */
    private const array MODULES = [
        'auth' => [
            'packages' => [
                'infocyph/otp' => [
                    'constraint' => '^6.1',
                    'role' => 'feature',
                    'features' => ['otp', 'passkey'],
                ],
                'web-auth/webauthn-lib' => [
                    'constraint' => '^5.3.5',
                    'role' => 'feature',
                    'features' => ['passkey'],
                ],
            ],
            'description' => 'Extended authentication with OTP-backed MFA, recovery codes, replay protection, and WebAuthn passkeys.',
            'aliases' => ['mfa', 'otp', 'passkey', 'passkeys', 'webauthn'],
            'config' => [],
            'schemas' => ['auth'],
            'features' => [
                'otp' => [
                    'description' => 'OTP-backed MFA, recovery codes, and replay protection.',
                    'aliases' => ['mfa', 'otp'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => ['ctype'],
                        'optional_extensions' => ['sodium'],
                        'packages' => [],
                    ],
                ],
                'passkey' => [
                    'description' => 'OTP-backed WebAuthn passkey ceremonies.',
                    'aliases' => ['passkey', 'passkeys', 'webauthn'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => ['ctype'],
                        'optional_extensions' => [],
                        'packages' => ['web-auth/webauthn-lib'],
                    ],
                ],
            ],
            'dependencies' => [],
            'platform' => [
                'extensions' => [],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'communication' => [
            'packages' => [
                'infocyph/talkingbytes' => [
                    'constraint' => '^2.1',
                    'role' => 'required',
                    'features' => [],
                ],
                'grpc/grpc' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => ['grpc'],
                ],
            ],
            'description' => 'HTTP, inbound/outbound email, webhook, and gRPC communication.',
            'aliases' => ['notifications', 'talkingbytes'],
            'config' => ['communication.php', 'notifications.php'],
            'schemas' => [],
            'features' => [
                'grpc' => [
                    'description' => 'Native generated PHP gRPC clients.',
                    'aliases' => ['grpc'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => ['grpc'],
                        'optional_extensions' => [],
                        'packages' => ['grpc/grpc'],
                    ],
                ],
            ],
            'dependencies' => [],
            'platform' => [
                'extensions' => ['curl', 'fileinfo', 'openssl'],
                'optional_extensions' => ['grpc', 'iconv', 'imap', 'mbstring', 'posix', 'sodium'],
                'packages' => [],
            ],
        ],
        'database' => [
            'packages' => [
                'infocyph/dblayer' => [
                    'constraint' => '^5.1',
                    'role' => 'required',
                    'features' => [],
                ],
            ],
            'description' => 'Database connections, queries, repositories, schema, migrations, and persistence.',
            'aliases' => ['db', 'dblayer'],
            'config' => ['database.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => ['pdo'],
                'optional_extensions' => ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'pdo_sqlsrv'],
                'packages' => [],
            ],
        ],
        'filesystem' => [
            'packages' => [
                'infocyph/pathwise' => [
                    'constraint' => '^4.1',
                    'role' => 'required',
                    'features' => [],
                ],
                'league/flysystem-async-aws-s3' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-aws-s3-v3' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-azure-blob-storage' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-ftp' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-google-cloud-storage' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-gridfs' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-memory' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-path-prefixing' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-read-only' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-sftp-v2' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-sftp-v3' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-webdav' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
                'league/flysystem-ziparchive' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => [],
                ],
            ],
            'description' => 'Filesystem, storage, uploads, downloads, archives, sync, and retention.',
            'aliases' => ['files', 'pathwise', 'storage'],
            'config' => ['filesystem.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => ['fileinfo'],
                'optional_extensions' => ['posix', 'simplexml', 'xmlreader', 'zip'],
                'packages' => [],
            ],
        ],
        'logging' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Structured PSR-3 logging and redacted exception reporting.',
            'aliases' => ['log', 'logs'],
            'config' => ['logging.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => [],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'messaging' => [
            'packages' => [
                'infocyph/omnibus' => [
                    'constraint' => '^2.6',
                    'role' => 'required',
                    'features' => [],
                ],
                'aws/aws-sdk-php' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => ['sqs'],
                ],
                'infocyph/runwire' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => ['runwire'],
                ],
                'php-amqplib/php-amqplib' => [
                    'constraint' => null,
                    'role' => 'optional',
                    'features' => ['amqp'],
                ],
            ],
            'description' => 'Events, messages, queues, handler middleware, retries, workers, optional process pools, workflows, and scheduled-message dispatch.',
            'aliases' => ['events', 'omnibus', 'queue', 'queues'],
            'config' => ['messaging.php'],
            'schemas' => ['messaging'],
            'features' => [
                'amqp' => [
                    'description' => 'AMQP transport backend integration.',
                    'aliases' => ['amqp'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => [],
                        'optional_extensions' => [],
                        'packages' => ['php-amqplib/php-amqplib'],
                    ],
                ],
                'runwire' => [
                    'description' => 'Runwire-backed process supervision.',
                    'aliases' => ['runwire'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => [],
                        'optional_extensions' => [],
                        'packages' => ['infocyph/runwire'],
                    ],
                ],
                'sqs' => [
                    'description' => 'AWS SQS transport backend integration.',
                    'aliases' => ['sqs'],
                    'dependencies' => [],
                    'platform' => [
                        'extensions' => [],
                        'optional_extensions' => [],
                        'packages' => ['aws/aws-sdk-php'],
                    ],
                ],
            ],
            'dependencies' => [
                [
                    'type' => 'module',
                    'target' => 'database',
                    'reason' => 'Durable database-backed messaging requires DBLayer.',
                    'when' => [
                        'key' => 'messaging.durable.enabled',
                        'operator' => 'equals',
                        'value' => true,
                    ],
                ],
            ],
            'platform' => [
                'extensions' => ['pcntl', 'posix'],
                'optional_extensions' => ['memcached', 'msgpack', 'redis'],
                'packages' => [],
            ],
        ],
        'operations' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Maintenance state, execution history, persistent-runtime control, process visibility, and operational diagnostics.',
            'aliases' => ['ops', 'runtime'],
            'config' => ['operations.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => [],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'resources' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'JsonDispatch application response resources and envelopes.',
            'aliases' => ['json', 'jsondispatch', 'responses'],
            'config' => ['responses.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => [],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'security' => [
            'packages' => [
                'infocyph/epicrypt' => [
                    'constraint' => '^3.1',
                    'role' => 'required',
                    'features' => [],
                ],
            ],
            'description' => 'Cryptography, secrets, password/token security, and key management.',
            'aliases' => ['crypto', 'epicrypt'],
            'config' => ['security.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => ['hash', 'json', 'openssl', 'sodium'],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'session' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Browser sessions, CSRF protection, flash data, and session locking.',
            'aliases' => ['sessions'],
            'config' => ['session.php'],
            'schemas' => ['session'],
            'features' => [],
            'dependencies' => [],
            'platform' => [
                'extensions' => [],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
        'validation' => [
            'packages' => [
                'infocyph/reqshield' => [
                    'constraint' => '^3.2',
                    'role' => 'required',
                    'features' => [],
                ],
            ],
            'description' => 'Request, command, configuration, schema, sanitization, and database validation.',
            'aliases' => ['reqshield', 'validator'],
            'config' => ['validation.php'],
            'schemas' => [],
            'features' => [],
            'dependencies' => [
                [
                    'type' => 'module',
                    'target' => 'database',
                    'reason' => 'Database-aware validation rules require DBLayer.',
                    'when' => [
                        'key' => 'validation.database_connection',
                        'operator' => 'not-empty',
                    ],
                ],
            ],
            'platform' => [
                'extensions' => ['fileinfo', 'hash', 'mbstring'],
                'optional_extensions' => [],
                'packages' => [],
            ],
        ],
    ];

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return self::MODULES;
    }

    /**
     * Return packages currently owned by the module lifecycle.
     *
     * Batch 3 will make feature selection narrow this set; until then all
     * required and feature packages preserve the existing install behavior.
     *
     * @param array<string,mixed> $definition
     * @phpstan-param ModuleDefinition $definition
     * @return array<string,string>
     */
    public function managedPackages(array $definition): array
    {
        $packages = [];
        foreach ($definition['packages'] as $package => $requirement) {
            if ($requirement['role'] === 'optional' || $requirement['constraint'] === null) {
                continue;
            }

            $packages[$package] = $requirement['constraint'];
        }

        return $packages;
    }

    /** @return ResolvedModule */
    public function resolve(string $module): array
    {
        $normalized = strtolower(trim($module));

        foreach (self::MODULES as $name => $definition) {
            if ($normalized === $name
                || isset($this->managedPackages($definition)[$normalized])
                || in_array($normalized, $definition['aliases'], true)
            ) {
                return ['name' => $name] + $definition;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown module "%s". Available modules: %s.',
            $module,
            implode(', ', array_keys(self::MODULES)),
        ));
    }

    public function validate(): void
    {
        new ModuleCatalogValidator()->validate(self::MODULES);
    }
}
