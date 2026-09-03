<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDescriptor;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Command\CommandResolver;
use Infocyph\Foundation\Command\ParsedInput;
use Infocyph\Foundation\Command\TerminalIO;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Infocyph\InterMix\DI\ProductionContainer;

final class FoundationProductionCommandResolverProbe implements CommandHandlerInterface
{
    public static int $runs = 0;

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('runtime:production-probe')
            ->description('Prove command resolution through the generated runtime boundary.')
            ->group('Testing');
    }

    public function run(CommandContext $context): int
    {
        unset($context);
        self::$runs++;

        return 17;
    }
}

it('resolves command handlers through the runtime-neutral application facade', function (): void {
    FoundationProductionCommandResolverProbe::$runs = 0;

    $runtime = new class extends ProductionContainer {
        private ?ExecutionScope $execution = null;

        public function get(string $id): mixed
        {
            return match ($id) {
                ExecutionScope::class => $this->execution ??= new ExecutionScope($this, RuntimeMode::Cli),
                RuntimeExecutionState::class => new RuntimeExecutionState(),
                FoundationProductionCommandResolverProbe::class => new FoundationProductionCommandResolverProbe(),
                default => throw new RuntimeException(sprintf('Unknown test service "%s".', $id)),
            };
        }

        public function has(string $id): bool
        {
            return in_array($id, [
                ExecutionScope::class,
                RuntimeExecutionState::class,
                FoundationProductionCommandResolverProbe::class,
            ], true);
        }

        protected function slotFor(string $id): ?int
        {
            unset($id);

            return null;
        }
    };
    $application = new Application(
        config: new ConfigRepository(['app' => ['env' => 'production']]),
        container: $runtime,
        providers: new ServiceRegistry(),
        bootstrapper: new Bootstrapper(),
        runtimeMode: RuntimeMode::Cli,
        bindDevelopmentCore: false,
    );
    $descriptor = CommandDescriptor::fromClass(FoundationProductionCommandResolverProbe::class);
    $input = ParsedInput::fromArgv(
        ['infbyte', $descriptor->definition->commandName()],
        $descriptor->definition,
    );

    $exitCode = new CommandResolver($application)->run(
        $descriptor,
        $input,
        new TerminalIO(silentMode: true),
    );

    expect($exitCode)->toBe(17)
        ->and(FoundationProductionCommandResolverProbe::$runs)->toBe(1);

    expect(fn() => $application->container())
        ->toThrow(LogicException::class, 'mutable InterMix development container is unavailable');
});
