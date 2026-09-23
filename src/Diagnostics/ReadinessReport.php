<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Cache\CacheSchemaManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;
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
        $checks = $this->baseChecks();
        $checks['configuration'] = $this->configurationReadiness();
        $capabilities = new ConfiguredCapabilities($this->application->config());

        if ($capabilities->enabled('auth') && $this->oauthEnabled()) {
            $checks['oauth:signing'] = $this->oauthSigningReadiness();
        }

        foreach ($this->requiredPackages() as $name => $requirement) {
            $checks['module:' . $name] = [
                'ready' => \Composer\InstalledVersions::isInstalled($requirement['package']),
                'detail' => $requirement['package'] . ' ' . $requirement['constraint'],
            ];
        }

        $this->appendSchemaChecks($checks, $capabilities);

        return [
            'ready' => !array_any($checks, static fn(array $check): bool => !$check['ready']),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string,array{ready:bool,detail:string}> $checks
     * @param list<array{name:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     */
    private function appendCacheSchemaRows(array &$checks, array $schemas): void
    {
        foreach ($schemas as $schema) {
            if ($schema['applicable']) {
                $checks['schema:' . $schema['name']] = [
                    'ready' => $schema['installed'],
                    'detail' => $schema['state'] . ': ' . $schema['detail'],
                ];
            }
        }
    }

    /**
     * @param array<string,array{ready:bool,detail:string}> $checks
     */
    private function appendSchemaChecks(array &$checks, ConfiguredCapabilities $capabilities): void
    {
        $schemas = new ModuleSchemaManager($this->application, new ModuleCatalog());

        foreach (['auth', 'session'] as $module) {
            if ($capabilities->enabled($module)) {
                $this->appendSchemaRows($checks, $schemas->status($module));
            }
        }

        if ($capabilities->enabled('cache')) {
            $this->appendCacheSchemaRows(
                $checks,
                new CacheSchemaManager($this->application)->statuses(),
            );
        }
    }

    /**
     * @param array<string,array{ready:bool,detail:string}> $checks
     * @param list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}> $schemas
     */
    private function appendSchemaRows(array &$checks, array $schemas): void
    {
        foreach ($schemas as $schema) {
            if ($schema['applicable']) {
                $checks['schema:' . $schema['name']] = [
                    'ready' => $schema['installed'],
                    'detail' => $schema['state'] . ': ' . $schema['detail'],
                ];
            }
        }
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
        if ($config->get('auth.drivers.storage', 'memory') === 'database') {
            $this->selectPackage($required, $catalog, 'database');
        }
        if ($config->get('auth.drivers.mfa', 'simple') === 'otp') {
            $this->selectPackage($required, $catalog, 'auth', 'infocyph/otp', 'auth:otp');
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
        }
        if ($config->get('auth.oauth.enabled', false) === true) {
            $this->selectPackage($required, $catalog, 'database');
            $this->selectPackage($required, $catalog, 'security');
        }
    }

    /** @return array<string,array{ready:bool,detail:string}> */
    private function baseChecks(): array
    {
        return [
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
    }

    /** @return array{ready:bool,detail:string} */
    private function configurationReadiness(): array
    {
        $config = $this->application->config();
        $messages = [
            ...new ConfigValidator($config)->validateForProduction()->messages(),
            ...array_map(
                static fn($issue): string => $issue->message,
                new ProductionSecurityValidator($config)->validate(),
            ),
        ];

        $capabilities = new ConfiguredCapabilities($config);
        if ($capabilities->enabled('auth') && $config->get('auth.drivers.mfa', 'simple') === 'otp') {
            $messages = [
                ...$messages,
                ...array_map(
                    static fn($issue): string => $issue->message,
                    new OtpConfigValidator($config)->validate(true),
                ),
            ];
        }

        return [
            'ready' => $messages === [],
            'detail' => $messages === []
                ? 'valid for production'
                : implode('; ', array_values(array_unique($messages))),
        ];
    }

    /** @param array<string,array{package:string,constraint:string}> $required */
    private function databasePackages(array &$required, ModuleCatalog $catalog, ConfigRepository $config): void
    {
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

    private function oauthEnabled(): bool
    {
        return $this->application->config()->get('auth.oauth.enabled', false) === true;
    }

    /** @return array{ready:bool,detail:string} */
    private function oauthSigningReadiness(): array
    {
        try {
            $keys = new OAuthSigningKeyResolver($this->application->config())->resolve();

            return [
                'ready' => true,
                'detail' => sprintf('active key %s using %s', $keys->activeKeyId, $keys->algorithm->value),
            ];
        } catch (\Throwable) {
            // Key locators/material are deployment secrets and must not be echoed by readiness diagnostics.
            return [
                'ready' => false,
                'detail' => 'OAuth signing key readiness failed; verify configured key locators, active key and public-key set.',
            ];
        }
    }

    /** @return array<string,array{package:string,constraint:string}> */
    private function requiredPackages(): array
    {
        $config = $this->application->config();
        $catalog = new ModuleCatalog();
        $required = [];

        $capabilities = new ConfiguredCapabilities($config);
        if ($capabilities->enabled('auth')) {
            $this->authPackages($required, $catalog, $config);
        }
        if ($capabilities->enabled('session')) {
            $this->sessionPackages($required, $catalog, $config);
        }
        if ($capabilities->enabled('database') || $capabilities->enabled('validation')) {
            $this->databasePackages($required, $catalog, $config);
        }
        if ($capabilities->enabled('messaging') || $capabilities->enabled('validation')) {
            $this->applicationPackages($required, $catalog);
        }

        return $required;
    }

    /** @param array<string,array{package:string,constraint:string}> $requirements */
    private function selectPackage(
        array &$requirements,
        ModuleCatalog $modules,
        string $module,
        ?string $package = null,
        ?string $label = null,
    ): void
    {
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
        if ($config->get('session.driver', 'file') === 'database') {
            $this->selectPackage($required, $catalog, 'database');
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
