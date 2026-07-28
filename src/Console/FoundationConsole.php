<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console;

use Closure;
use Infocyph\Console\Application as ConsoleApplication;
use Infocyph\Console\Command\CommandContract;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\Command\AppReadyCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaInstallCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaStatusCommand;
use Infocyph\Foundation\Console\Command\CommandCacheCommand;
use Infocyph\Foundation\Console\Command\CommandClearCommand;
use Infocyph\Foundation\Console\Command\ConfigCacheCommand;
use Infocyph\Foundation\Console\Command\ConfigClearCommand;
use Infocyph\Foundation\Console\Command\CreateClassCommand;
use Infocyph\Foundation\Console\Command\CreateCommandCommand;
use Infocyph\Foundation\Console\Command\CreateControllerCommand;
use Infocyph\Foundation\Console\Command\CreateEnumCommand;
use Infocyph\Foundation\Console\Command\CreateEventCommand;
use Infocyph\Foundation\Console\Command\CreateExceptionCommand;
use Infocyph\Foundation\Console\Command\CreateInterfaceCommand;
use Infocyph\Foundation\Console\Command\CreateJobCommand;
use Infocyph\Foundation\Console\Command\CreateListenerCommand;
use Infocyph\Foundation\Console\Command\CreateMiddlewareCommand;
use Infocyph\Foundation\Console\Command\CreatePolicyCommand;
use Infocyph\Foundation\Console\Command\CreateProviderCommand;
use Infocyph\Foundation\Console\Command\CreateRepositoryCommand;
use Infocyph\Foundation\Console\Command\CreateServiceCommand;
use Infocyph\Foundation\Console\Command\CreateTestCommand;
use Infocyph\Foundation\Console\Command\CreateTraitCommand;
use Infocyph\Foundation\Console\Command\CreateWorkerCommand;
use Infocyph\Foundation\Console\Command\ModuleInstallCommand;
use Infocyph\Foundation\Console\Command\ModuleListCommand;
use Infocyph\Foundation\Console\Command\ModuleRemoveCommand;
use Infocyph\Foundation\Console\Command\OptimizeClearCommand;
use Infocyph\Foundation\Console\Command\OptimizeCommand;
use Infocyph\Foundation\Console\Command\RouteCacheCommand;
use Infocyph\Foundation\Console\Command\RouteClearCommand;
use Infocyph\Foundation\Console\Command\ScheduleCacheCommand;
use Infocyph\Foundation\Console\Command\ScheduleClearCommand;
use Infocyph\Foundation\Console\Command\ScheduleListCommand;
use Infocyph\Foundation\Console\Command\ScheduleRunCommand;
use Infocyph\Foundation\Console\Command\ScheduleWorkCommand;
use Infocyph\Foundation\Console\Command\WorkerListCommand;
use Infocyph\Foundation\Console\Command\WorkerRunCommand;

final class FoundationConsole
{
    /** @var array<string, class-string<CommandContract>> */
    private const array SYSTEM_COMMANDS = [
        'app:ready' => AppReadyCommand::class,
        'auth:schema:status' => AuthSchemaStatusCommand::class,
        'auth:schema:install' => AuthSchemaInstallCommand::class,
        'config:cache' => ConfigCacheCommand::class,
        'config:clear' => ConfigClearCommand::class,
        'command:cache' => CommandCacheCommand::class,
        'command:clear' => CommandClearCommand::class,
        'create:class' => CreateClassCommand::class,
        'create:command' => CreateCommandCommand::class,
        'create:controller' => CreateControllerCommand::class,
        'create:enum' => CreateEnumCommand::class,
        'create:event' => CreateEventCommand::class,
        'create:exception' => CreateExceptionCommand::class,
        'create:interface' => CreateInterfaceCommand::class,
        'create:job' => CreateJobCommand::class,
        'create:listener' => CreateListenerCommand::class,
        'create:middleware' => CreateMiddlewareCommand::class,
        'create:policy' => CreatePolicyCommand::class,
        'create:provider' => CreateProviderCommand::class,
        'create:repository' => CreateRepositoryCommand::class,
        'create:service' => CreateServiceCommand::class,
        'create:test' => CreateTestCommand::class,
        'create:trait' => CreateTraitCommand::class,
        'create:worker' => CreateWorkerCommand::class,
        'module:install' => ModuleInstallCommand::class,
        'module:list' => ModuleListCommand::class,
        'module:remove' => ModuleRemoveCommand::class,
        'optimize' => OptimizeCommand::class,
        'optimize:clear' => OptimizeClearCommand::class,
        'route:cache' => RouteCacheCommand::class,
        'route:clear' => RouteClearCommand::class,
        'schedule:cache' => ScheduleCacheCommand::class,
        'schedule:clear' => ScheduleClearCommand::class,
        'schedule:list' => ScheduleListCommand::class,
        'schedule:run' => ScheduleRunCommand::class,
        'schedule:work' => ScheduleWorkCommand::class,
        'worker:list' => WorkerListCommand::class,
        'worker:run' => WorkerRunCommand::class,
    ];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $applicationCommands
     * @return array<string, class-string<CommandContract>>
     */
    public static function commands(array $applicationCommands): array
    {
        $commands = self::SYSTEM_COMMANDS;

        foreach ($applicationCommands as $name => $command) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException(
                    'Application commands must be an explicit command-name-to-class map.',
                );
            }
            if (isset($commands[$name])) {
                throw new \InvalidArgumentException(sprintf(
                    'Application command "%s" conflicts with a Foundation system command.',
                    $name,
                ));
            }
            if (!is_string($command) || !is_a($command, CommandContract::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Application command "%s" must implement %s.',
                    $name,
                    CommandContract::class,
                ));
            }

            $commands[$name] = $command;
        }

        return $commands;
    }

    /**
     * @param Closure(?string): Application $applicationFactory
     * @param array<array-key, mixed> $commands
     */
    public static function create(
        Closure $applicationFactory,
        string $name = 'foundation',
        string $version = 'dev',
        array $commands = [],
        ?string $commandManifest = null,
    ): ConsoleApplication {
        $runtime = new FoundationConsoleRuntime($applicationFactory);
        $builder = ConsoleApplication::configure()
            ->name($name)
            ->version($version)
            ->commandGroup('System', ...array_keys(self::SYSTEM_COMMANDS))
            ->containerProvider($runtime)
            ->configurationProvider($runtime)
            ->lockProviderFactory($runtime->lockProvider(...));

        if ($commandManifest !== null && is_file($commandManifest)) {
            $builder->commandManifest($commandManifest);
        } else {
            $builder->commands(self::commands($commands));
        }

        return $builder->build();
    }
}
