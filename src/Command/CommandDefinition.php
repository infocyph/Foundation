<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\RuntimeMode;

final class CommandDefinition
{
    /** @var list<string> */
    private array $aliases = [];

    /** @var list<array{name:string,description:string,required:bool,variadic:bool}> */
    private array $arguments = [];

    /** @var list<string> */
    private array $capabilities = [];

    private string $description = '';

    private CommandExecutionPolicy $execution;

    private string $group = 'Application';

    private bool $hidden = false;

    private string $name = '';

    /** @var array<string, array{name:string,description:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}> */
    private array $options = [];

    private RuntimeMode $runtime = RuntimeMode::Cli;

    /** @param list<string> $capabilities */
    public function __construct(
        string $name = '',
        string $description = '',
        string $group = 'Application',
        RuntimeMode $runtime = RuntimeMode::Cli,
        array $capabilities = [],
    ) {
        $this->execution = new CommandExecutionPolicy();
        if ($name !== '') {
            $this->name($name);
        }
        $this->description($description);
        $this->group($group);
        $this->runtime($runtime);
        foreach ($capabilities as $capability) {
            $this->capability($capability);
        }
    }

    /** @param array<string, mixed> $manifest */
    public static function fromManifest(array $manifest): self
    {
        $definition = self::definitionFromScalars($manifest);

        foreach (self::stringList($manifest['capabilities'] ?? [], 'capabilities') as $capability) {
            $definition->capability($capability);
        }
        foreach (self::stringList($manifest['aliases'] ?? [], 'aliases') as $alias) {
            $definition->alias($alias);
        }

        $hidden = $manifest['hidden'] ?? false;
        if (!is_bool($hidden)) {
            throw new \UnexpectedValueException('Compiled command hidden metadata must be boolean.');
        }
        $definition->hidden($hidden);

        self::applyArguments($definition, $manifest['arguments'] ?? []);
        self::applyOptions($definition, $manifest['options'] ?? []);
        $definition->execution(CommandExecutionPolicy::fromManifest(
            self::associative($manifest['execution'] ?? [], 'execution'),
        ));

        return $definition;
    }

    public function alias(string $alias): self
    {
        $this->assertCommandName($alias, 'alias');
        if ($alias === $this->name) {
            throw new \InvalidArgumentException('A command alias cannot equal its canonical name.');
        }
        if (!in_array($alias, $this->aliases, true)) {
            $this->aliases[] = $alias;
        }

        return $this;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function argument(
        string $name,
        string $description = '',
        bool $required = false,
        bool $variadic = false,
    ): self {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid command argument name "%s".', $name));
        }
        if (array_any($this->arguments, static fn(array $argument): bool => $argument['name'] === $name)) {
            throw new \InvalidArgumentException(sprintf('Command argument "%s" is already defined.', $name));
        }
        if (array_any($this->arguments, static fn(array $argument): bool => $argument['variadic'])) {
            throw new \LogicException('A variadic command argument must be the final argument.');
        }
        if ($required && array_any($this->arguments, static fn(array $argument): bool => !$argument['required'])) {
            throw new \LogicException('Required command arguments cannot follow optional arguments.');
        }

        $this->arguments[] = [
            'name' => $name,
            'description' => trim($description),
            'required' => $required,
            'variadic' => $variadic,
        ];

        return $this;
    }

    /** @return list<array{name:string,description:string,required:bool,variadic:bool}> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function assertComplete(): void
    {
        if ($this->name === '') {
            throw new \LogicException('Command definition must declare a name.');
        }
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function capability(string $capability): self
    {
        $capability = trim($capability);
        if ($capability === '' || preg_match('/^[a-z][a-z0-9_-]*$/D', $capability) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid command capability "%s".', $capability));
        }
        if (!in_array($capability, $this->capabilities, true)) {
            $this->capabilities[] = $capability;
        }

        return $this;
    }

    public function commandDescription(): string
    {
        return $this->description;
    }

    public function commandGroup(): string
    {
        return $this->group;
    }

    public function commandName(): string
    {
        return $this->name;
    }

    public function commandRuntime(): RuntimeMode
    {
        return $this->runtime;
    }

    public function description(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function execution(CommandExecutionPolicy $policy): self
    {
        $this->execution = $policy;

        return $this;
    }

    public function executionPolicy(): CommandExecutionPolicy
    {
        return $this->execution;
    }

    public function group(string $group): self
    {
        $group = trim($group);
        if ($group === '') {
            throw new \InvalidArgumentException('Command group cannot be empty.');
        }
        $this->group = $group;

        return $this;
    }

    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function name(string $name): self
    {
        $this->assertCommandName($name, 'name');
        $this->name = $name;

        return $this;
    }

    public function option(
        string $name,
        string $description = '',
        ?string $short = null,
        bool $acceptsValue = false,
        bool $multiple = false,
        bool $negatable = false,
    ): self {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid command option name "%s".', $name));
        }
        if ($short !== null && preg_match('/^[A-Za-z0-9]$/D', $short) !== 1) {
            throw new \InvalidArgumentException('Command option shortcuts must be one alphanumeric character.');
        }
        if ($multiple && !$acceptsValue) {
            throw new \LogicException('Only value options may accept multiple values.');
        }
        if ($negatable && $acceptsValue) {
            throw new \LogicException('Only flag options may be negatable.');
        }
        if (isset($this->options[$name])) {
            throw new \InvalidArgumentException(sprintf('Command option "%s" is already defined.', $name));
        }
        if ($short !== null && array_any(
            $this->options,
            static fn(array $option): bool => $option['short'] === $short,
        )) {
            throw new \InvalidArgumentException(sprintf('Command option shortcut "-%s" is already defined.', $short));
        }

        $this->options[$name] = [
            'name' => $name,
            'description' => trim($description),
            'short' => $short,
            'accepts_value' => $acceptsValue,
            'multiple' => $multiple,
            'negatable' => $negatable,
        ];

        return $this;
    }

    /** @return array{name:string,description:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}|null */
    public function optionByShort(string $short): ?array
    {
        foreach ($this->options as $option) {
            if ($option['short'] === $short) {
                return $option;
            }
        }

        return null;
    }

    /** @return array<string, array{name:string,description:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool}> */
    public function options(): array
    {
        return $this->options;
    }

    public function runtime(RuntimeMode $runtime): self
    {
        $this->runtime = $runtime;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        $this->assertComplete();

        return [
            'name' => $this->name,
            'description' => $this->description,
            'group' => $this->group,
            'runtime' => $this->runtime->value,
            'capabilities' => $this->capabilities,
            'aliases' => $this->aliases,
            'hidden' => $this->hidden,
            'arguments' => $this->arguments,
            'options' => array_values($this->options),
            'execution' => $this->execution->toManifest(),
        ];
    }

    private static function applyArguments(self $definition, mixed $arguments): void
    {
        if (!is_array($arguments)) {
            throw new \UnexpectedValueException('Compiled command arguments metadata must be a list.');
        }

        foreach ($arguments as $argument) {
            $metadata = self::argumentMetadata($argument);
            $definition->argument(
                $metadata['name'],
                $metadata['description'],
                $metadata['required'],
                $metadata['variadic'],
            );
        }
    }

    private static function applyOptions(self $definition, mixed $options): void
    {
        if (!is_array($options)) {
            throw new \UnexpectedValueException('Compiled command options metadata must be a list.');
        }

        foreach ($options as $option) {
            $metadata = self::optionMetadata($option);
            $definition->option(
                $metadata['name'],
                $metadata['description'],
                $metadata['short'],
                $metadata['accepts_value'],
                $metadata['multiple'],
                $metadata['negatable'],
            );
        }
    }

    /** @return array{name:string,description:string,required:bool,variadic:bool} */
    private static function argumentMetadata(mixed $argument): array
    {
        if (!is_array($argument)) {
            throw new \UnexpectedValueException('Compiled command argument metadata is invalid.');
        }

        $name = $argument['name'] ?? null;
        $description = $argument['description'] ?? null;
        $required = $argument['required'] ?? null;
        $variadic = $argument['variadic'] ?? null;
        if (!is_string($name) || !is_string($description) || !is_bool($required) || !is_bool($variadic)) {
            throw new \UnexpectedValueException('Compiled command argument metadata is invalid.');
        }

        return compact('name', 'description', 'required', 'variadic');
    }

    /** @return array<string,mixed> */
    private static function associative(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Compiled command %s metadata must be an array.', $field));
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(sprintf(
                    'Compiled command %s metadata must use string keys.',
                    $field,
                ));
            }
            $normalized[$key] = $item;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $manifest */
    private static function definitionFromScalars(array $manifest): self
    {
        $name = $manifest['name'] ?? null;
        $description = $manifest['description'] ?? '';
        $group = $manifest['group'] ?? 'Application';
        $runtime = $manifest['runtime'] ?? RuntimeMode::Cli->value;
        if (!is_string($name) || !is_string($description) || !is_string($group) || !is_string($runtime)) {
            throw new \UnexpectedValueException('Compiled command metadata contains invalid scalar fields.');
        }

        try {
            return new self($name, $description, $group, RuntimeMode::from($runtime));
        } catch (\ValueError $exception) {
            throw new \UnexpectedValueException(
                sprintf('Invalid compiled command runtime "%s".', $runtime),
                previous: $exception,
            );
        }
    }

    /** @return array{name:string,description:string,short:?string,accepts_value:bool,multiple:bool,negatable:bool} */
    private static function optionMetadata(mixed $option): array
    {
        if (!is_array($option)) {
            throw new \UnexpectedValueException('Compiled command option metadata is invalid.');
        }

        $name = $option['name'] ?? null;
        $description = $option['description'] ?? null;
        $short = $option['short'] ?? null;
        $acceptsValue = $option['accepts_value'] ?? null;
        $multiple = $option['multiple'] ?? null;
        $negatable = $option['negatable'] ?? null;
        if (!is_string($name)
            || !is_string($description)
            || !($short === null || is_string($short))
            || !is_bool($acceptsValue)
            || !is_bool($multiple)
            || !is_bool($negatable)
        ) {
            throw new \UnexpectedValueException('Compiled command option metadata is invalid.');
        }

        return [
            'name' => $name,
            'description' => $description,
            'short' => $short,
            'accepts_value' => $acceptsValue,
            'multiple' => $multiple,
            'negatable' => $negatable,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Compiled command %s metadata must be a string list.', $field));
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException(sprintf(
                    'Compiled command %s metadata must be a string list.',
                    $field,
                ));
            }
            $items[] = $item;
        }

        return $items;
    }

    private function assertCommandName(string $name, string $field): void
    {
        if ($name === '' || preg_match('/^[a-z][a-z0-9:_-]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid command %s "%s".', $field, $name));
        }
    }
}
