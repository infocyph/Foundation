<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

/**
 * @phpstan-type ModuleDefinition array{packages:array<string,string>,built_in?:bool,description:string,aliases:list<string>,config:list<string>}
 * @phpstan-type ResolvedModule array{name:string,packages:array<string,string>,built_in?:bool,description:string,aliases:list<string>,config:list<string>}
 */
final class ModuleCatalog
{
    /** @var array<string, ModuleDefinition> */
    private const array MODULES = [
        'auth' => [
            'packages' => [
                'infocyph/otp' => '^6.0',
                'web-auth/webauthn-lib' => '^5.3.5',
            ],
            'description' => 'Extended authentication with OTP-backed MFA, recovery codes, replay protection, and WebAuthn passkeys.',
            'aliases' => ['mfa', 'otp', 'passkey', 'passkeys', 'webauthn'],
            'config' => [],
        ],
        'cache' => [
            'packages' => [
                'infocyph/cachelayer' => '^3.1.3',
            ],
            'description' => 'Cache stores, locks, counters, response caching, and shared authentication/runtime state.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
        ],
        'communication' => [
            'packages' => [
                'infocyph/talkingbytes' => '^2.0',
            ],
            'description' => 'HTTP, inbound/outbound email, webhook, and gRPC communication.',
            'aliases' => ['notifications', 'talkingbytes'],
            'config' => ['communication.php', 'notifications.php'],
        ],
        'database' => [
            'packages' => [
                'infocyph/dblayer' => '^4.1',
            ],
            'description' => 'Database connections, queries, repositories, schema, migrations, and persistence.',
            'aliases' => ['db', 'dblayer'],
            'config' => ['database.php'],
        ],
        'filesystem' => [
            'packages' => [
                'infocyph/pathwise' => '^3.1',
            ],
            'description' => 'Filesystem, storage, uploads, downloads, archives, sync, and retention.',
            'aliases' => ['files', 'pathwise', 'storage'],
            'config' => ['filesystem.php'],
        ],
        'logging' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Structured PSR-3 logging and redacted exception reporting.',
            'aliases' => ['log', 'logs'],
            'config' => ['logging.php'],
        ],
        'messaging' => [
            'packages' => [
                'infocyph/omnibus' => '^2.2',
            ],
            'description' => 'Events, messages, queues, retries, workers, optional process pools, workflows, and scheduled-message dispatch.',
            'aliases' => ['events', 'omnibus', 'queue', 'queues'],
            'config' => ['messaging.php'],
        ],
        'resources' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'JsonDispatch application response resources and envelopes.',
            'aliases' => ['json', 'jsondispatch', 'responses'],
            'config' => ['responses.php'],
        ],
        'security' => [
            'packages' => [
                'infocyph/epicrypt' => '^2.1',
            ],
            'description' => 'Cryptography, secrets, password/token security, and key management.',
            'aliases' => ['crypto', 'epicrypt'],
            'config' => ['security.php'],
        ],
        'session' => [
            'packages' => [],
            'built_in' => true,
            'description' => 'Browser sessions, CSRF protection, flash data, and session locking.',
            'aliases' => ['sessions'],
            'config' => ['session.php'],
        ],
        'validation' => [
            'packages' => [
                'infocyph/reqshield' => '^3.0',
            ],
            'description' => 'Request, command, configuration, schema, sanitization, and database validation.',
            'aliases' => ['reqshield', 'validator'],
            'config' => ['validation.php'],
        ],
    ];

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return self::MODULES;
    }

    /** @return ResolvedModule */
    public function resolve(string $module): array
    {
        $normalized = strtolower(trim($module));

        foreach (self::MODULES as $name => $definition) {
            if ($normalized === $name
                || isset($definition['packages'][$normalized])
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
}
