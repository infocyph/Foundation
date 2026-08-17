<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Diagnostics;

use Infocyph\Foundation\Application\Application;
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

        foreach (new ModuleCatalog()->all() as $name => $module) {
            if (($module['built_in'] ?? false) === true || $module['package'] === null) {
                continue;
            }
            $package = $module['package'];
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
}
