<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final class CommandRegistry
{
    /** @var array<string, CommandDescriptor> */
    private array $aliases = [];

    /** @var array<string, CommandDescriptor> */
    private array $commands = [];

    /** @param array<array-key, mixed> $applicationCommands */
    public function __construct(
        array $applicationCommands = [],
        ?CommandCatalog $catalog = null,
        bool $includeSystem = true,
    ) {
        if ($includeSystem) {
            foreach (($catalog ?? new CommandCatalog())->descriptors() as $descriptor) {
                $this->register($descriptor);
            }
        }

        foreach ($applicationCommands as $route => $handler) {
            if (!is_string($handler) || !is_a($handler, CommandHandlerInterface::class, true)) {
                throw new \UnexpectedValueException(sprintf(
                    'Application command route "%s" must reference a %s class.',
                    is_string($route) ? $route : (string) $route,
                    CommandHandlerInterface::class,
                ));
            }
            $this->register(CommandDescriptor::fromClass(
                $handler,
                is_string($route) ? $route : null,
            ));
        }
    }

    /** @return array<string, CommandDescriptor> */
    public function all(): array
    {
        $commands = $this->commands;
        ksort($commands);

        return $commands;
    }

    public function find(string $name): ?CommandDescriptor
    {
        return $this->commands[$name] ?? $this->aliases[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]) || isset($this->aliases[$name]);
    }

    public function register(CommandDescriptor $descriptor): void
    {
        $name = $descriptor->definition->commandName();
        if ($this->has($name)) {
            throw new \UnexpectedValueException(sprintf('Command route "%s" is already registered.', $name));
        }

        foreach ($descriptor->definition->aliases() as $alias) {
            if ($this->has($alias)) {
                throw new \UnexpectedValueException(sprintf('Command alias "%s" is already registered.', $alias));
            }
        }

        $this->commands[$name] = $descriptor;
        foreach ($descriptor->definition->aliases() as $alias) {
            $this->aliases[$alias] = $descriptor;
        }
    }

    /** @return list<string> */
    public function suggestions(string $name, int $limit = 3): array
    {
        if ($limit < 1 || $name === '') {
            return [];
        }

        $needle = strtolower($name);
        $candidates = [];
        foreach ($this->routeNames() as $candidate) {
            $descriptor = $this->find($candidate);
            if ($descriptor?->definition->isHidden()) {
                continue;
            }
            $distance = levenshtein($needle, strtolower($candidate));
            $threshold = max(2, (int) floor(max(strlen($needle), strlen($candidate)) / 3));
            if ($distance <= $threshold || str_contains(strtolower($candidate), $needle)) {
                $candidates[$candidate] = $distance;
            }
        }

        uksort(
            $candidates,
            static fn(string $left, string $right): int => [$candidates[$left], $left]
                <=> [$candidates[$right], $right],
        );

        return array_slice(array_keys($candidates), 0, $limit);
    }

    /** @return list<CommandDescriptor> */
    public function visible(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(CommandDescriptor $descriptor): bool => !$descriptor->definition->isHidden(),
        ));
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        $commands = [];
        foreach ($this->all() as $name => $descriptor) {
            $commands[$name] = $descriptor->toManifest();
        }

        return [
            'version' => 1,
            'commands' => $commands,
        ];
    }

    /** @param array<string, mixed> $manifest */
    public static function fromManifest(array $manifest): self
    {
        $version = $manifest['version'] ?? null;
        $commands = $manifest['commands'] ?? null;
        if ($version !== 1 || !is_array($commands)) {
            throw new \UnexpectedValueException('Compiled command manifest has an unsupported format.');
        }

        $registry = new self(includeSystem: false);
        foreach ($commands as $name => $metadata) {
            if (!is_string($name) || !is_array($metadata)) {
                throw new \UnexpectedValueException('Compiled command manifest contains an invalid command entry.');
            }
            $descriptor = CommandDescriptor::fromManifest(self::associative($metadata));
            if ($descriptor->definition->commandName() !== $name) {
                throw new \UnexpectedValueException(sprintf(
                    'Compiled command key "%s" does not match descriptor name "%s".',
                    $name,
                    $descriptor->definition->commandName(),
                ));
            }
            $registry->register($descriptor);
        }

        return $registry;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<string, mixed>
     */
    private static function associative(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Compiled command metadata must use string keys.');
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        return [...array_keys($this->commands), ...array_keys($this->aliases)];
    }
}
