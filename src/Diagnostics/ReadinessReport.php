<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Config\ProductionSecurityValidator;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleSchemaManager;

final readonly class ReadinessReport
{
    public function __construct(private Application $application) {}

    /** @return array{ready:bool,checks:array<string,array{ready:bool,detail:string}>} */
    public function generate(): array
    {
        $checks = [
            'php' => [
                'ready' => version_compare(PHP_VERSION, '8.4.0', '>='),
                'detail' => PHP_VERSION,
            ],
            'base_path' => [
                'ready' => is_dir($this->application->basePath()) && is_readable($this->application->basePath()),
                'detail' => $this->application->basePath(),
            ],
            'storage' => [
                'ready' => is_dir($this->application->storagePath()) && is_writable($this->application->storagePath()),
                'detail' => $this->application->storagePath(),
            ],
            'runtime' => [
                'ready' => true,
                'detail' => $this->application->runtimeMode()->value,
            ],
        ];

        $validation = new ConfigValidator($this->application->config())->validateForProduction();
        $messages = $validation->messages();
        $messages = [
            ...$messages,
            ...array_map(
                static fn($issue): string => $issue->message,
                new ProductionSecurityValidator($this->application->config())->validate(),
            ),
        ];
        if ($this->application->config()->get('auth.drivers.mfa', 'simple') === 'otp') {
            $messages = [
                ...$messages,
                ...array_map(
                    static fn($issue): string => $issue->message,
                    new OtpConfigValidator($this->application->config())->validate(true),
                ),
            ];
        }
        $checks['configuration'] = [
            'ready' => $messages === [],
            'detail' => $messages === [] ? 'valid for production' : implode('; ', array_values(array_unique($messages))),
        ];

        foreach ($this->requiredPackages() as $name => $requirement) {
            $checks['module:' . $name] = [
                'ready' => \Composer\InstalledVersions::isInstalled($requirement['package']),
                'detail' => $requirement['package'] . ' ' . $requirement['constraint'],
            ];
        }

        $catalog = new ModuleCatalog();
        $schemas = new ModuleSchemaManager($this->application, $catalog);
        foreach (['auth', 'cache', 'session'] as $module) {
            foreach ($schemas->status($module) as $schema) {
                if (!$schema['applicable']) {
                    continue;
                }
                $checks['schema:' . $schema['name']] = [
                    'ready' => $schema['installed'],
                    'detail' => $schema['state'] . ': ' . $schema['detail'],
                ];
            }
        }

        return [
            'ready' => !array_any($checks, static fn(array $check): bool => !$check['ready']),
            'checks' => $checks,
        ];
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function applicationPackages(array &$required, ModuleCatalog $catalog): void
    {
        if ($this->messagingConfigured()) {
            $this->selectPackage($required, $catalog, 'messaging');
        }
        if ($this->validationConfigured()) {
            $this->selectPackage($required, $catalog, 'validation');
        }
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function authPackages(array &$required, ModuleCatalog $catalog, ConfigRepository $config): void
    {
        if ($config->get('auth.drivers.cache', 'array') === 'cache') {
            $this->selectPackage($required, $catalog, 'cache');
        }
        if ($config->get('auth.drivers.storage', 'memory') === 'database') {
            $this->selectPackage($required, $catalog, 'database');
        }
        if ($config->get('auth.drivers.mfa', 'simple') === 'otp') {
            $this->selectPackage($required, $catalog, 'auth', 'infocyph/otp', 'auth:otp');
            $this->selectPackage($required, $catalog, 'cache');
        }
        if ($config->get('auth.drivers.notifications', 'collect') === 'talkingbytes') {
            $this->selectPackage($required, $catalog, 'communication');
        }
        if ($config->get('auth.drivers.passwords', 'native') === 'security'
            || $config->get('auth.drivers.tokens', 'simple') === 'security'
        ) {
            $this->selectPackage($required, $catalog, 'security');
        }
        if ($config->get('auth.drivers.passkey', 'memory') === 'webauthn') {
            $this->selectPackage($required, $catalog, 'auth', 'web-auth/webauthn-lib', 'auth:passkeys');
            $this->selectPackage($required, $catalog, 'cache');
        }
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function databasePackages(array &$required, ModuleCatalog $catalog, ConfigRepository $config): void
    {
        $migrationLock = $config->get('database.migrations.lock_store');
        if (is_string($migrationLock) && trim($migrationLock) !== '') {
            $this->selectPackage($required, $catalog, 'cache');
        }

        $validationConnection = $config->get('validation.database_connection');
        if (is_string($validationConnection) && trim($validationConnection) !== '') {
            $this->selectPackage($required, $catalog, 'database');
        }
    }

    private function messagingConfigured(): bool
    {
        $config = $this->application->config();
        foreach (['routes', 'handlers', 'listeners', 'scheduled_messages', 'workers'] as $key) {
            $value = $config->get('messaging.' . $key, []);
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return $config->get('messaging.forward_auth_events', false) === true;
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function operationsPackages(array &$required, ModuleCatalog $catalog, ConfigRepository $config): void
    {
        foreach (['maintenance', 'runtime_control'] as $surface) {
            if ($config->get('operations.' . $surface . '.driver', 'file') === 'cache') {
                $this->selectPackage($required, $catalog, 'cache');
            }
        }
    }

    /** @return array<string,array{package:string,constraint:string}> */
    private function requiredPackages(): array
    {
        $config = $this->application->config();
        $catalog = new ModuleCatalog();
        $required = [];

        $this->authPackages($required, $catalog, $config);
        $this->sessionPackages($required, $catalog, $config);
        $this->databasePackages($required, $catalog, $config);
        $this->operationsPackages($required, $catalog, $config);
        $this->applicationPackages($required, $catalog);

        return $required;
    }

    /** @param array<string,array{package:string,constraint:string}> $requirements */
    private function selectPackage(
        array &$requirements,
        ModuleCatalog $modules,
        string $module,
        ?string $package = null,
        ?string $label = null,
    ): void {
        $definition = $modules->resolve($module);
        $packages = $definition['packages'];

        if ($package !== null) {
            $constraint = $packages[$package] ?? throw new \LogicException(sprintf(
                'Module "%s" does not provide package "%s".',
                $definition['name'],
                $package,
            ));
            $requirements[$label ?? $definition['name']] = [
                'package' => $package,
                'constraint' => $constraint,
            ];

            return;
        }

        $multiple = count($packages) > 1;
        foreach ($packages as $dependency => $constraint) {
            $key = $label ?? ($multiple
                ? $definition['name'] . ':' . str_replace(['infocyph/', 'web-auth/'], '', $dependency)
                : $definition['name']);
            $requirements[$key] = [
                'package' => $dependency,
                'constraint' => $constraint,
            ];
        }
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function sessionPackages(array &$required, ModuleCatalog $catalog, ConfigRepository $config): void
    {
        $driver = $config->get('session.driver', 'file');
        if ($driver === 'cache') {
            $this->selectPackage($required, $catalog, 'cache');
        } elseif ($driver === 'database') {
            $this->selectPackage($required, $catalog, 'database');
        }
        if ($config->get('session.lock.enabled', false) === true) {
            $this->selectPackage($required, $catalog, 'cache');
        }
    }

    private function validationConfigured(): bool
    {
        $config = $this->application->config();
        foreach (['validation.schemas', 'validation.extend'] as $key) {
            $value = $config->get($key, []);
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        $connection = $config->get('validation.database_connection');

        return is_string($connection) && $connection !== '';
    }
}
