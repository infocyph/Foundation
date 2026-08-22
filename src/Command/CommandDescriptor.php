<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class CommandDescriptor
{
    /** @param class-string<CommandHandlerInterface>|null $handler */
    public function __construct(
        public CommandDefinition $definition,
        public ?string $handler = null,
        public bool $system = false,
    ) {
        $definition->assertComplete();
    }

    /**
     * @param class-string<CommandHandlerInterface> $handler
     */
    public static function fromClass(string $handler, ?string $routeName = null): self
    {
        if (!is_a($handler, CommandHandlerInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Command handler "%s" must implement %s.',
                $handler,
                CommandHandlerInterface::class,
            ));
        }

        $definition = new CommandDefinition();
        $handler::define($definition);
        if ($definition->commandName() === '' && $routeName !== null) {
            $definition->name($routeName);
        }
        $definition->assertComplete();

        if ($routeName !== null && $definition->commandName() !== $routeName) {
            throw new \UnexpectedValueException(sprintf(
                'Command route "%s" does not match %s::define() name "%s".',
                $routeName,
                $handler,
                $definition->commandName(),
            ));
        }

        return new self($definition, $handler);
    }

    /** @return array<string, mixed> */
    public function toManifest(): array
    {
        return [
            'handler' => $this->handler,
            'system' => $this->system,
            'definition' => $this->definition->toManifest(),
        ];
    }

    /** @param array<string, mixed> $manifest */
    public static function fromManifest(array $manifest): self
    {
        $definition = $manifest['definition'] ?? null;
        $handler = $manifest['handler'] ?? null;
        $system = $manifest['system'] ?? false;
        if (!is_array($definition)
            || !(is_string($handler) || $handler === null)
            || !is_bool($system)
        ) {
            throw new \UnexpectedValueException('Compiled command descriptor metadata is invalid.');
        }

        /** @var class-string<CommandHandlerInterface>|null $handler */
        return new self(CommandDefinition::fromManifest($definition), $handler, $system);
    }
}
