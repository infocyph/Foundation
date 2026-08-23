<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
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

        $validation = (new ConfigValidator($this->application->config()))->validateForProduction();
        $messages = $validation->messages();
        $messages = [
            ...$messages,
            ...array_map(
                static fn($issue): string => $issue->message,
                (new ProductionSecurityValidator($this->application->config()))->validate(),
            ),
        ];
        if ($this->application->config()->get('auth.drivers.mfa', 'simple') === 'otp') {
            $messages = [
                ...$messages,
                ...array_map(
                    static fn($issue): string => $issue->message,
                    (new OtpConfigValidator($this->application->config()))->validate(true),
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

    /** @return array<string,array{package:string,constraint:string}> */
    private function requiredPackages(): array
    {
        $config = $this->application->config();
        $catalog = new ModuleCatalog();
        $required = [];
        $select = static function (
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
        };

        if ($config->get('auth.drivers.cache', 'array') === 'cache') {
            $select($required, $catalog, 'cache');
        }
        if ($config->get('auth.drivers.storage', 'memory') === 'database') {
            $select($required, $catalog, 'database');
        }
        if ($config->get('auth.drivers.mfa', 'simple') === 'otp') {
            $select($required, $catalog, 'auth', 'infocyph/otp', 'auth:otp');
            $select($required, $catalog, 'cache');
        }
        if ($config->get('auth.drivers.notifications', 'collect') === 'talkingbytes') {
            $select($required, $catalog, 'communication');
        }
        if ($config->get('auth.drivers.passwords', 'native') === 'security'
            || $config->get('auth.drivers.tokens', 'simple') === 'security'
        ) {
            $select($required, $catalog, 'security');
        }
        if ($config->get('auth.drivers.passkey', 'memory') === 'webauthn') {
            $select($required, $catalog, 'auth', 'web-auth/webauthn-lib', 'auth:passkeys');
            $select($required, $catalog, 'cache');
        }

        $sessionDriver = $config->get('session.driver', 'file');
        if ($sessionDriver === 'cache') {
            $select($required, $catalog, 'cache');
        } elseif ($sessionDriver === 'database') {
            $select($required, $catalog, 'database');
        }
        if ($config->get('session.lock.enabled', false) === true) {
            $select($required, $catalog, 'cache');
        }

        $migrationLock = $config->get('database.migrations.lock_store');
        if (is_string($migrationLock) && trim($migrationLock) !== '') {
            $select($required, $catalog, 'cache');
        }

        foreach (['maintenance', 'runtime_control'] as $surface) {
            if ($config->get('operations.' . $surface . '.driver', 'file') === 'cache') {
                $select($required, $catalog, 'cache');
            }
        }

        if ($this->messagingConfigured()) {
            $select($required, $catalog, 'messaging');
        }
        if ($this->validationConfigured()) {
            $select($required, $catalog, 'validation');
        }
        $validationConnection = $config->get('validation.database_connection');
        if (is_string($validationConnection) && trim($validationConnection) !== '') {
            $select($required, $catalog, 'database');
        }

        return $required;
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
