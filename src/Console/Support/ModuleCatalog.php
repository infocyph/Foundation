<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

final class ModuleCatalog
{
    /**
     * @var array<string, array{
     *     package: string,
     *     description: string,
     *     aliases: list<string>
     * }>
     */
    private const array MODULES = [
        'cache' => [
            'package' => 'infocyph/cachelayer',
            'description' => 'Cache stores, response caching, throttling, and cache-backed auth.',
            'aliases' => ['cachelayer'],
        ],
        'communication' => [
            'package' => 'infocyph/talkingbytes',
            'description' => 'HTTP, webhook, gRPC, email, and notification integrations.',
            'aliases' => ['notifications', 'talkingbytes'],
        ],
        'crypto' => [
            'package' => 'infocyph/epicrypt',
            'description' => 'Cryptography, secrets, and hardened auth token/password adapters.',
            'aliases' => ['epicrypt', 'security'],
        ],
        'db' => [
            'package' => 'infocyph/dblayer',
            'description' => 'Database connections, persistence, and database-backed auth.',
            'aliases' => ['database', 'dblayer'],
        ],
        'filesystem' => [
            'package' => 'infocyph/pathwise',
            'description' => 'Filesystem disks, uploads, downloads, and file operations.',
            'aliases' => ['files', 'pathwise'],
        ],
        'otp' => [
            'package' => 'infocyph/otp',
            'description' => 'One-time passwords and OTP-backed MFA.',
            'aliases' => ['mfa'],
        ],
        'passkeys' => [
            'package' => 'web-auth/webauthn-lib',
            'description' => 'WebAuthn passkey registration and authentication.',
            'aliases' => ['passkey', 'webauthn'],
        ],
        'validation' => [
            'package' => 'infocyph/reqshield',
            'description' => 'Request, command, configuration, and database validation.',
            'aliases' => ['reqshield', 'validator'],
        ],
    ];

    /**
     * @return array<string, array{
     *     package: string,
     *     description: string,
     *     aliases: list<string>
     * }>
     */
    public function all(): array
    {
        return self::MODULES;
    }

    /**
     * @return array{
     *     name: string,
     *     package: string,
     *     description: string,
     *     aliases: list<string>
     * }
     */
    public function resolve(string $module): array
    {
        $normalized = strtolower(trim($module));

        foreach (self::MODULES as $name => $definition) {
            if ($normalized === $name
                || $normalized === $definition['package']
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
