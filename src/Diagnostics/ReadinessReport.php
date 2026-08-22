<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Config\ProductionSecurityValidator;
use Infocyph\Foundation\Module\ModuleCatalog;

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
                    (new OtpConfigValidator($this->application->config()))->validate(),
                ),
            ];
        }
        $checks['configuration'] = [
            'ready' => $messages === [],
            'detail' => $messages === [] ? 'valid for production' : implode('; ', array_values(array_unique($messages))),
        ];

        $catalog = new ModuleCatalog();
        foreach ($this->requiredModules() as $name) {
            $module = $catalog->resolve($name);
            $package = $module['package'];
            if (($module['built_in'] ?? false) === true || $package === null) {
                continue;
            }

            $checks['module:' . $name] = [
                'ready' => \Composer\InstalledVersions::isInstalled($package),
                'detail' => $package . ' ' . ($module['constraint'] ?? ''),
            ];
        }

        return [
            'ready' => !array_any($checks, static fn(array $check): bool => !$check['ready']),
            'checks' => $checks,
        ];
    }

    /** @return list<string> */
    private function requiredModules(): array
    {
        $config = $this->application->config();
        $required = [];
        $select = static function (array &$modules, string $module): void {
            $modules[$module] = true;
        };

        if ($config->get('auth.drivers.cache', 'array') === 'cache') {
            $select($required, 'cache');
        }
        if ($config->get('auth.drivers.storage', 'memory') === 'database') {
            $select($required, 'db');
        }
        if ($config->get('auth.drivers.mfa', 'simple') === 'otp') {
            $select($required, 'otp');
            $select($required, 'cache');
        }
        if ($config->get('auth.drivers.notifications', 'collect') === 'talkingbytes') {
            $select($required, 'communication');
        }
        if ($config->get('auth.drivers.passwords', 'native') === 'security'
            || $config->get('auth.drivers.tokens', 'simple') === 'security'
        ) {
            $select($required, 'crypto');
        }
        if ($config->get('auth.drivers.passkey', 'memory') === 'webauthn') {
            $select($required, 'passkeys');
        }

        $sessionDriver = $config->get('session.driver', 'file');
        if ($sessionDriver === 'cache') {
            $select($required, 'cache');
        } elseif ($sessionDriver === 'database') {
            $select($required, 'db');
        }

        if ($this->messagingConfigured()) {
            $select($required, 'messaging');
        }
        if ($this->validationConfigured()) {
            $select($required, 'validation');
        }

        return array_keys($required);
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
