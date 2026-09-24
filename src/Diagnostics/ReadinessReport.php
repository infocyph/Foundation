<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Cache\CacheSchemaManager;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\Internal\ConfiguredCapabilities;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Config\ProductionSecurityValidator;
use Infocyph\Foundation\Module\ModuleCatalog;
use Infocyph\Foundation\Module\ModuleSchemaManager;
use Infocyph\Foundation\Module\ModuleStateResolver;

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

        foreach (new ModuleStateResolver($this->application, new ModuleCatalog())->all() as $module) {
            if (!$module['enabled']) {
                continue;
            }

            $detail = $module['blockers'] !== []
                ? implode('; ', $module['blockers'])
                : ($module['warnings'] !== [] ? implode('; ', $module['warnings']) : $module['status']);
            $checks['module:' . $module['name']] = [
                'ready' => $module['ready'],
                'detail' => $detail,
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


}
