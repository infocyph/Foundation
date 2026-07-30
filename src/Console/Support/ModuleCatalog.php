<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

final class ModuleCatalog
{
    /**
     * @var array<string, array{
     *     package: string|null,
     *     built_in?: bool,
     *     description: string,
     *     aliases: list<string>,
     *     config: list<string>
     * }>
     */
    private const array MODULES = [
        'cache' => [
            'package' => 'infocyph/cachelayer',
            'description' => 'Cache stores, response caching, throttling, and cache-backed auth.',
            'aliases' => ['cachelayer'],
            'config' => ['cache.php'],
        ],
        'communication' => [
            'package' => 'infocyph/talkingbytes',
            'description' => 'HTTP, webhook, gRPC, email, and notification integrations.',
            'aliases' => ['notifications', 'talkingbytes'],
            'config' => ['communication.php', 'notifications.php'],
        ],
        'crypto' => [
            'package' => 'infocyph/epicrypt',
            'description' => 'Cryptography, secrets, and hardened auth token/password adapters.',
            'aliases' => ['epicrypt', 'security'],
            'config' => ['security.php'],
        ],
        'db' => [
            'package' => 'infocyph/dblayer',
            'description' => 'Database connections, persistence, and database-backed auth.',
            'aliases' => ['database', 'dblayer'],
            'config' => ['database.php'],
        ],
        'filesystem' => [
            'package' => 'infocyph/pathwise',
            'description' => 'Filesystem disks, uploads, downloads, and file operations.',
            'aliases' => ['files', 'pathwise'],
            'config' => ['filesystem.php'],
        ],
        'logging' => [
            'package' => null,
            'built_in' => true,
            'description' => 'Structured PSR-3 logging and redacted exception reporting.',
            'aliases' => ['log', 'logs'],
            'config' => ['logging.php'],
        ],
        'messaging' => [
            'package' => null,
            'built_in' => true,
            'description' => 'Omnibus events, queues, scheduled messages, and worker scopes.',
            'aliases' => ['events', 'omnibus', 'queue', 'queues'],
            'config' => ['messaging.php'],
        ],
        'otp' => [
            'package' => 'infocyph/otp',
            'description' => 'One-time passwords and OTP-backed MFA.',
            'aliases' => ['mfa'],
            'config' => [],
        ],
        'passkeys' => [
            'package' => 'web-auth/webauthn-lib',
            'description' => 'WebAuthn passkey registration and authentication.',
            'aliases' => ['passkey', 'webauthn'],
            'config' => [],
        ],
        'resources' => [
            'package' => null,
            'built_in' => true,
            'description' => 'JsonDispatch 3.0.0 resources, envelopes, issues, and pagination.',
            'aliases' => ['json', 'jsondispatch', 'responses'],
            'config' => ['responses.php'],
        ],
        'session' => [
            'package' => null,
            'built_in' => true,
            'description' => 'Route-selected browser sessions, CSRF protection, flash data, and session locking.',
            'aliases' => ['sessions'],
            'config' => ['session.php'],
        ],
        'validation' => [
            'package' => 'infocyph/reqshield',
            'description' => 'Request, command, configuration, and database validation.',
            'aliases' => ['reqshield', 'validator'],
            'config' => ['validation.php'],
        ],
    ];

    /**
     * @return array<string, array{
     *     package: string|null,
     *     built_in?: bool,
     *     description: string,
     *     aliases: list<string>,
     *     config: list<string>
     * }>
     */
    public function all(): array
    {
        return self::MODULES;
    }

    /**
     * @return array{
     *     name: string,
     *     package: string|null,
     *     built_in?: bool,
     *     description: string,
     *     aliases: list<string>,
     *     config: list<string>
     * }
     */
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
