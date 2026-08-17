<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

/**
 * @phpstan-type ModuleDefinition array{package:string|null,constraint:string|null,built_in?:bool,description:string,aliases:list<string>,config:list<string>}
 * @phpstan-type ResolvedModule array{name:string,package:string|null,constraint:string|null,built_in?:bool,description:string,aliases:list<string>,config:list<string>}
 */
final class ModuleCatalog
{
    /** @var array<string, array{package:string|null,constraint:string|null,built_in?:bool,description:string,aliases:list<string>,config:list<string>}> */
    private const array MODULES = [
        'cache' => [
            'package' => 'infocyph/cachelayer',
            'constraint' => '^3.1.2',
            'description' => 'Cache stores, locks, counters, response caching, and authentication state.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
        ],
        'communication' => [
            'package' => 'infocyph/talkingbytes',
            'constraint' => '^2.0',
            'description' => 'HTTP, email, webhook, and gRPC communication.',
            'aliases' => ['notifications', 'talkingbytes'],
            'config' => ['communication.php', 'notifications.php'],
        ],
        'crypto' => [
            'package' => 'infocyph/epicrypt',
            'constraint' => '^2.1',
            'description' => 'Cryptography, secrets, passwords, tokens, and key security.',
            'aliases' => ['epicrypt', 'security'],
            'config' => ['security.php'],
        ],
        'db' => [
            'package' => 'infocyph/dblayer',
            'constraint' => '^4.0',
            'description' => 'Database connections, queries, repositories, schema, migrations, and persistence.',
            'aliases' => ['database', 'dblayer'],
            'config' => ['database.php'],
        ],
        'filesystem' => [
            'package' => 'infocyph/pathwise',
            'constraint' => '^3.1',
            'description' => 'Filesystem, storage, uploads, downloads, archives, and sync.',
            'aliases' => ['files', 'pathwise'],
            'config' => ['filesystem.php'],
        ],
        'logging' => [
            'package' => null,
            'constraint' => null,
            'built_in' => true,
            'description' => 'Structured PSR-3 logging and redacted exception reporting.',
            'aliases' => ['log', 'logs'],
            'config' => ['logging.php'],
        ],
        'messaging' => [
            'package' => 'infocyph/omnibus',
            'constraint' => '^2.1.1',
            'description' => 'Events, messages, queues, retries, workflows, and scheduled-message dispatch.',
            'aliases' => ['events', 'omnibus', 'queue', 'queues'],
            'config' => ['messaging.php'],
        ],
        'otp' => [
            'package' => 'infocyph/otp',
            'constraint' => '^6.0',
            'description' => 'OTP-backed MFA, recovery codes, and replay-safe verification.',
            'aliases' => ['mfa'],
            'config' => [],
        ],
        'passkeys' => [
            'package' => 'web-auth/webauthn-lib',
            'constraint' => '^5.3.5',
            'description' => 'WebAuthn passkey registration and authentication.',
            'aliases' => ['passkey', 'webauthn'],
            'config' => [],
        ],
        'resources' => [
            'package' => null,
            'constraint' => null,
            'built_in' => true,
            'description' => 'JsonDispatch application response resources and envelopes.',
            'aliases' => ['json', 'jsondispatch', 'responses'],
            'config' => ['responses.php'],
        ],
        'session' => [
            'package' => null,
            'constraint' => null,
            'built_in' => true,
            'description' => 'Browser sessions, CSRF protection, flash data, and session locking.',
            'aliases' => ['sessions'],
            'config' => ['session.php'],
        ],
        'validation' => [
            'package' => 'infocyph/reqshield',
            'constraint' => '^3.0',
            'description' => 'Request, command, configuration, and database validation.',
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
                || ($definition['package'] !== null && $normalized === $definition['package'])
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
