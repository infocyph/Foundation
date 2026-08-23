<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Generator;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Http\Resource\JsonResource;

final readonly class ArtifactGenerator
{
    /** @var array<string, array{directory:string,namespace:string,suffix:string,stub:string,requires?:class-string,install?:string}> */
    private const array ARTIFACTS = [
        'class' => ['directory' => 'app', 'namespace' => 'App', 'suffix' => '', 'stub' => 'class.stub'],
        'command' => ['directory' => 'app/Command', 'namespace' => 'App\\Command', 'suffix' => 'Command', 'stub' => 'command.stub'],
        'controller' => ['directory' => 'app/Http/Controllers', 'namespace' => 'App\\Http\\Controllers', 'suffix' => 'Controller', 'stub' => 'controller.stub'],
        'enum' => ['directory' => 'app/Enums', 'namespace' => 'App\\Enums', 'suffix' => '', 'stub' => 'enum.stub'],
        'event' => ['directory' => 'app/Events', 'namespace' => 'App\\Events', 'suffix' => 'Event', 'stub' => 'event.stub'],
        'exception' => ['directory' => 'app/Exceptions', 'namespace' => 'App\\Exceptions', 'suffix' => 'Exception', 'stub' => 'exception.stub'],
        'interface' => ['directory' => 'app/Contracts', 'namespace' => 'App\\Contracts', 'suffix' => 'Interface', 'stub' => 'interface.stub'],
        'job' => ['directory' => 'app/Jobs', 'namespace' => 'App\\Jobs', 'suffix' => 'Job', 'stub' => 'job.stub'],
        'listener' => ['directory' => 'app/Listeners', 'namespace' => 'App\\Listeners', 'suffix' => 'Listener', 'stub' => 'listener.stub'],
        'middleware' => ['directory' => 'app/Http/Middleware', 'namespace' => 'App\\Http\\Middleware', 'suffix' => 'Middleware', 'stub' => 'middleware.stub'],
        'migration' => ['directory' => 'app/Database/Migration', 'namespace' => 'App\\Database\\Migration', 'suffix' => 'Migration', 'stub' => 'migration.stub', 'requires' => Migration::class, 'install' => 'php infbyte module:install database'],
        'policy' => ['directory' => 'app/Policies', 'namespace' => 'App\\Policies', 'suffix' => 'Policy', 'stub' => 'policy.stub'],
        'provider' => ['directory' => 'app/Providers', 'namespace' => 'App\\Providers', 'suffix' => 'ServiceProvider', 'stub' => 'provider.stub'],
        'repository' => ['directory' => 'app/Repositories', 'namespace' => 'App\\Repositories', 'suffix' => 'Repository', 'stub' => 'repository.stub', 'requires' => Repository::class, 'install' => 'php infbyte module:install database'],
        'resource' => ['directory' => 'app/Http/Resources', 'namespace' => 'App\\Http\\Resources', 'suffix' => 'Resource', 'stub' => 'resource.stub', 'requires' => JsonResource::class, 'install' => 'Foundation resources are built in'],
        'service' => ['directory' => 'app/Services', 'namespace' => 'App\\Services', 'suffix' => 'Service', 'stub' => 'service.stub'],
        'seeder' => ['directory' => 'app/Database/Seeder', 'namespace' => 'App\\Database\\Seeder', 'suffix' => 'Seeder', 'stub' => 'seeder.stub', 'requires' => Seeder::class, 'install' => 'php infbyte module:install database'],
        'test' => ['directory' => 'tests/Feature', 'namespace' => 'Tests\\Feature', 'suffix' => 'Test', 'stub' => 'test.stub'],
        'trait' => ['directory' => 'app/Concerns', 'namespace' => 'App\\Concerns', 'suffix' => 'Trait', 'stub' => 'trait.stub'],
        'worker' => ['directory' => 'app/Worker', 'namespace' => 'App\\Worker', 'suffix' => 'Worker', 'stub' => 'worker.stub'],
    ];

    public function __construct(private Application $application) {}

    /** @return array{class:string,path:string} */
    public function create(string $artifact, string $name, bool $force = false, ?string $table = null): array
    {
        if ($artifact === 'config') {
            return $this->createConfig($name, $force);
        }

        $definition = self::ARTIFACTS[$artifact] ?? throw new \InvalidArgumentException(sprintf('Unknown artifact type "%s".', $artifact));
        $this->assertRequirement($artifact, $definition);
        $segments = $this->segments($name);
        $class = array_pop($segments) . $definition['suffix'];
        if ($definition['suffix'] !== '' && str_ends_with($class, $definition['suffix'] . $definition['suffix'])) {
            $class = substr($class, 0, -strlen($definition['suffix']));
        }
        $namespace = implode('\\', array_filter([$definition['namespace'], implode('\\', $segments)]));
        $directory = implode('/', array_filter([$definition['directory'], implode('/', $segments)]));
        $path = $this->application->basePath($directory . '/' . $class . '.php');
        $contents = $this->render(
            $definition['stub'],
            $namespace,
            $class,
            $segments,
            $artifact === 'repository' ? $this->table($class, $table) : null,
        );
        $this->write($path, $contents, $force);

        return ['class' => implode('\\', array_filter([$namespace, $class])), 'path' => $path];
    }

    /** @return array{class:string,path:string} */
    private function createConfig(string $name, bool $force): array
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Config names must be non-empty application-relative names.');
        }
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf('Config segment "%s" is invalid.', $segment));
            }
        }
        $filename = array_pop($segments);
        if (str_ends_with($filename, '.php')) {
            $filename = substr($filename, 0, -4);
        }
        if ($filename === '') {
            throw new \InvalidArgumentException('Config filename cannot be empty.');
        }
        $directory = implode('/', array_filter(['config', implode('/', $segments)]));
        $path = $this->application->basePath($directory . '/' . $filename . '.php');
        $contents = file_get_contents(dirname(__DIR__, 2) . '/resources/stubs/create/config.stub');
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read config generator stub.');
        }
        $this->write($path, $contents, $force);

        return ['class' => 'config/' . implode('/', [...$segments, $filename]), 'path' => $path];
    }

    /** @param array{directory:string,namespace:string,suffix:string,stub:string,requires?:class-string,install?:string} $definition */
    private function assertRequirement(string $artifact, array $definition): void
    {
        $required = $definition['requires'] ?? null;
        if ($required === null || class_exists($required) || interface_exists($required)) {
            return;
        }

        throw new \LogicException(sprintf(
            'Creating a %s requires its optional module; run "%s".',
            $artifact,
            $definition['install'] ?? 'composer install',
        ));
    }

    /** @param list<string> $parents */
    private function commandName(string $class, array $parents): string
    {
        $class = preg_replace('/Command$/', '', $class) ?? $class;

        return implode(':', array_map(
            static fn(string $segment): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $segment) ?? $segment),
            [...$parents, $class],
        ));
    }

    private function description(string $class): string
    {
        $name = preg_replace('/(?<!^)[A-Z]/', ' $0', preg_replace('/Test$/', '', $class) ?? $class);

        return strtolower($name ?? $class);
    }

    private function migrationId(string $class): string
    {
        $name = preg_replace('/Migration$/', '', $class) ?? $class;
        $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);

        return gmdate('YmdHis') . '_' . $name;
    }

    /** @param list<string> $parents */
    private function render(string $stub, string $namespace, string $class, array $parents, ?string $table): string
    {
        $path = dirname(__DIR__, 2) . '/resources/stubs/create/' . $stub;
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read generator stub "%s".', $path));
        }

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ command }}', '{{ description }}', '{{ migration_id }}', '{{ table }}'],
            [$namespace, $class, $this->commandName($class, $parents), $this->description($class), $this->migrationId($class), var_export($table ?? '', true)],
            $contents,
        );
    }

    /** @return non-empty-list<non-falsy-string> */
    private function segments(string $name): array
    {
        if ($name === '' || str_contains($name, "\0") || preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $name) === 1) {
            throw new \InvalidArgumentException('Artifact names must be non-empty application-relative class names.');
        }

        $segments = [];
        foreach (explode('/', str_replace('\\', '/', trim($name))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Artifact names cannot contain empty or relative path segments.');
            }
            $words = preg_split('/[-_\s]+/', $segment, flags: PREG_SPLIT_NO_EMPTY);
            $normalized = is_array($words) ? implode('', array_map(ucfirst(...), $words)) : '';
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $normalized) !== 1) {
                throw new \InvalidArgumentException(sprintf('Artifact segment "%s" is invalid.', $segment));
            }
            $segments[] = $normalized;
        }

        return $segments;
    }

    private function table(string $class, ?string $configured): string
    {
        if ($configured !== null && $configured !== '') {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $configured) !== 1) {
                throw new \InvalidArgumentException('Repository tables must be valid identifiers.');
            }

            return $configured;
        }
        $name = preg_replace('/Repository$/', '', $class) ?? $class;
        $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
        if (preg_match('/[^aeiou]y$/', $name) === 1) {
            return substr($name, 0, -1) . 'ies';
        }
        if (preg_match('/(?:s|x|z|ch|sh)$/', $name) === 1) {
            return $name . 'es';
        }

        return $name . 's';
    }

    private function write(string $path, string $contents, bool $force): void
    {
        if ((is_file($path) || is_link($path)) && !$force) {
            throw new \RuntimeException(sprintf('Artifact already exists at "%s".', $path));
        }
        if (is_link($path)) {
            throw new \RuntimeException(sprintf('Refusing to replace symbolic-link artifact "%s".', $path));
        }
        $base = realpath($this->application->basePath());
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create artifact directory "%s".', $directory));
        }
        $resolved = realpath($directory);
        if ($base === false || $resolved === false || ($resolved !== $base && !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Generated artifacts must remain inside the application base path.');
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write temporary artifact "%s".', $temporary));
        }

        try {
            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to activate generated artifact "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
