<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class CommandResolver
{
    public function __construct(private Application $application) {}

    public function run(
        CommandDescriptor $descriptor,
        ParsedInput $input,
        CommandIO $io,
        ?ExecutionId $executionId = null,
    ): int {
        $definition = $descriptor->definition;
        if ($definition->commandRuntime() !== $this->application->runtimeMode()) {
            throw new \LogicException(sprintf(
                'Command "%s" requires the %s runtime; current runtime is %s.',
                $definition->commandName(),
                $definition->commandRuntime()->value,
                $this->application->runtimeMode()->value,
            ));
        }
        if ($descriptor->handler === null) {
            throw new \LogicException(sprintf(
                'System command "%s" has not yet been bound to a Foundation handler.',
                $definition->commandName(),
            ));
        }

        $executionId ??= ExecutionId::generate();
        $context = new CommandContext($input, $io, $executionId, $descriptor);

        $run = function () use ($descriptor, $context): int {
            $this->activateCapabilities($descriptor->definition);
            $command = $this->resolve($descriptor->handler);
            $exitCode = $command->run($context);
            if ($exitCode < 0 || $exitCode > 255) {
                throw new \UnexpectedValueException(sprintf(
                    'Command "%s" returned invalid exit code %d.',
                    $descriptor->definition->commandName(),
                    $exitCode,
                ));
            }

            return $exitCode;
        };

        // Scheduler and worker commands own persistent control loops. Their
        // individual schedule/job executions enter fresh scopes themselves.
        if ($this->application->runningInScheduler() || $this->application->runningInWorker()) {
            return $run();
        }

        return $this->application->execution()->run(
            $run,
            [
                ParsedInput::class => $input,
                CommandIO::class => $io,
                CommandContext::class => $context,
                CommandDescriptor::class => $descriptor,
                CommandDefinition::class => $definition,
            ],
            $executionId,
        );
    }

    private function activateCapabilities(CommandDefinition $definition): void
    {
        foreach ($definition->capabilities() as $capability) {
            $service = match ($capability) {
                'cache' => 'foundation.cache',
                'communication' => 'foundation.notifications',
                'crypto' => 'foundation.security',
                'db' => 'foundation.db',
                'filesystem' => 'foundation.filesystem',
                'messaging' => 'foundation.messaging',
                'validation' => 'foundation.validator',
                'web' => 'foundation.router',
                default => null,
            };
            if ($service !== null) {
                $this->application->make($service);
            }
        }
    }

    /** @param class-string<CommandHandlerInterface> $handler */
    private function resolve(string $handler): CommandHandlerInterface
    {
        return $this->application->make($handler);
    }
}
