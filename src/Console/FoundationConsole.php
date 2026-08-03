<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console;

use Closure;
use Infocyph\Console\Application as ConsoleApplication;
use Infocyph\Console\Command\CommandContract;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\Command\AboutCommand;
use Infocyph\Foundation\Console\Command\AppReadyCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaInstallCommand;
use Infocyph\Foundation\Console\Command\AuthSchemaStatusCommand;
use Infocyph\Foundation\Console\Command\CacheClearCommand;
use Infocyph\Foundation\Console\Command\CommandCacheCommand;
use Infocyph\Foundation\Console\Command\CommandClearCommand;
use Infocyph\Foundation\Console\Command\ConfigCacheCommand;
use Infocyph\Foundation\Console\Command\ConfigClearCommand;
use Infocyph\Foundation\Console\Command\ConfigShowCommand;
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
use Infocyph\Foundation\Console\Command\CreateMigrationCommand;
use Infocyph\Foundation\Console\Command\CreatePolicyCommand;
use Infocyph\Foundation\Console\Command\CreateProviderCommand;
use Infocyph\Foundation\Console\Command\CreateRepositoryCommand;
use Infocyph\Foundation\Console\Command\CreateSeederCommand;
use Infocyph\Foundation\Console\Command\CreateServiceCommand;
use Infocyph\Foundation\Console\Command\CreateTestCommand;
use Infocyph\Foundation\Console\Command\CreateTraitCommand;
use Infocyph\Foundation\Console\Command\CreateWorkerCommand;
use Infocyph\Foundation\Console\Command\DatabaseSeedCommand;
use Infocyph\Foundation\Console\Command\DatabaseShowCommand;
use Infocyph\Foundation\Console\Command\DatabaseTableCommand;
use Infocyph\Foundation\Console\Command\EnvironmentShowCommand;
use Infocyph\Foundation\Console\Command\MigrateCommand;
use Infocyph\Foundation\Console\Command\MigrateFreshCommand;
use Infocyph\Foundation\Console\Command\MigrateRefreshCommand;
use Infocyph\Foundation\Console\Command\MigrateResetCommand;
use Infocyph\Foundation\Console\Command\MigrateRollbackCommand;
use Infocyph\Foundation\Console\Command\MigrateStatusCommand;
use Infocyph\Foundation\Console\Command\ModuleInstallCommand;
use Infocyph\Foundation\Console\Command\ModuleListCommand;
use Infocyph\Foundation\Console\Command\ModuleRemoveCommand;
use Infocyph\Foundation\Console\Command\OptimizeClearCommand;
use Infocyph\Foundation\Console\Command\OptimizeCommand;
use Infocyph\Foundation\Console\Command\RouteCacheCommand;
use Infocyph\Foundation\Console\Command\RouteClearCommand;
use Infocyph\Foundation\Console\Command\RouteListCommand;
use Infocyph\Foundation\Console\Command\ScheduleCacheCommand;
use Infocyph\Foundation\Console\Command\ScheduleClearCommand;
use Infocyph\Foundation\Console\Command\ScheduleListCommand;
use Infocyph\Foundation\Console\Command\ScheduleRunCommand;
use Infocyph\Foundation\Console\Command\ScheduleWorkCommand;
use Infocyph\Foundation\Console\Command\SecretGenerateCommand;
use Infocyph\Foundation\Console\Command\ServeCommand;
use Infocyph\Foundation\Console\Command\SessionPruneCommand;
use Infocyph\Foundation\Console\Command\SessionSchemaInstallCommand;
use Infocyph\Foundation\Console\Command\SessionSchemaStatusCommand;
use Infocyph\Foundation\Console\Command\StorageLinkCommand;
use Infocyph\Foundation\Console\Command\WorkerListCommand;
use Infocyph\Foundation\Console\Command\WorkerRunCommand;
use Infocyph\InterMix\DI\Container;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;

final class FoundationConsole
{
    /** @var array<string, array<string, class-string<CommandContract>>> */
    private const array SYSTEM_COMMAND_GROUPS = [
        'Application' => [
            'about' => AboutCommand::class,
            'app:ready' => AppReadyCommand::class,
            'env:show' => EnvironmentShowCommand::class,
            'serve' => ServeCommand::class,
        ],
        'Authentication & Security' => [
            'auth:schema:status' => AuthSchemaStatusCommand::class,
            'auth:schema:install' => AuthSchemaInstallCommand::class,
            'secret:generate' => SecretGenerateCommand::class,
        ],
        'Cache' => [
            'cache:clear' => CacheClearCommand::class,
        ],
        'Configuration' => [
            'config:cache' => ConfigCacheCommand::class,
            'config:clear' => ConfigClearCommand::class,
            'config:show' => ConfigShowCommand::class,
        ],
        'Console' => [
            'command:cache' => CommandCacheCommand::class,
            'command:clear' => CommandClearCommand::class,
        ],
        'Database' => [
            'db:seed' => DatabaseSeedCommand::class,
            'db:show' => DatabaseShowCommand::class,
            'db:table' => DatabaseTableCommand::class,
            'migrate' => MigrateCommand::class,
            'migrate:fresh' => MigrateFreshCommand::class,
            'migrate:refresh' => MigrateRefreshCommand::class,
            'migrate:reset' => MigrateResetCommand::class,
            'migrate:rollback' => MigrateRollbackCommand::class,
            'migrate:status' => MigrateStatusCommand::class,
        ],
        'Generators' => [
            'create:class' => CreateClassCommand::class,
            'create:command' => CreateCommandCommand::class,
            'create:controller' => CreateControllerCommand::class,
            'create:enum' => CreateEnumCommand::class,
            'create:event' => CreateEventCommand::class,
            'create:exception' => CreateExceptionCommand::class,
            'create:interface' => CreateInterfaceCommand::class,
            'create:job' => CreateJobCommand::class,
            'create:listener' => CreateListenerCommand::class,
            'create:migration' => CreateMigrationCommand::class,
            'create:middleware' => CreateMiddlewareCommand::class,
            'create:policy' => CreatePolicyCommand::class,
            'create:provider' => CreateProviderCommand::class,
            'create:repository' => CreateRepositoryCommand::class,
            'create:seeder' => CreateSeederCommand::class,
            'create:service' => CreateServiceCommand::class,
            'create:test' => CreateTestCommand::class,
            'create:trait' => CreateTraitCommand::class,
            'create:worker' => CreateWorkerCommand::class,
        ],
        'Messaging' => [
            'queue:consume' => \Infocyph\Console\Omnibus\ConsumeCommand::class,
            'schedule:dispatch-message' => \Infocyph\Console\Omnibus\DispatchScheduledMessageCommand::class,
        ],
        'Modules' => [
            'module:install' => ModuleInstallCommand::class,
            'module:list' => ModuleListCommand::class,
            'module:remove' => ModuleRemoveCommand::class,
        ],
        'Optimization' => [
            'optimize' => OptimizeCommand::class,
            'optimize:clear' => OptimizeClearCommand::class,
        ],
        'Routing' => [
            'route:cache' => RouteCacheCommand::class,
            'route:clear' => RouteClearCommand::class,
            'route:list' => RouteListCommand::class,
        ],
        'Scheduling' => [
            'schedule:cache' => ScheduleCacheCommand::class,
            'schedule:clear' => ScheduleClearCommand::class,
            'schedule:list' => ScheduleListCommand::class,
            'schedule:run' => ScheduleRunCommand::class,
            'schedule:work' => ScheduleWorkCommand::class,
        ],
        'Sessions' => [
            'session:prune' => SessionPruneCommand::class,
            'session:schema:install' => SessionSchemaInstallCommand::class,
            'session:schema:status' => SessionSchemaStatusCommand::class,
        ],
        'Storage' => [
            'storage:link' => StorageLinkCommand::class,
        ],
        'Workers' => [
            'worker:list' => WorkerListCommand::class,
            'worker:run' => WorkerRunCommand::class,
        ],
    ];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $applicationCommands
     * @return array<string, class-string<CommandContract>>
     */
    public static function commands(array $applicationCommands): array
    {
        $commands = self::systemCommands();

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
     * @param Closure $applicationFactory Lazily constructs the selected application profile.
     * @phpstan-param Closure(?string): Application $applicationFactory
     * @psalm-param Closure(?string): Application $applicationFactory
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
            ->containerProvider($runtime)
            ->configurationProvider($runtime)
            ->lockProviderFactory($runtime->lockProvider(...))
            ->configureContainer(static function (Container $container) use ($runtime): void {
                if (!$container->has(ConsumerTask::class)) {
                    $container->factory(
                        ConsumerTask::class,
                        static fn() => $runtime->application()->make(ConsumerTask::class),
                    )->singleton();
                }
                if (!$container->has(ScheduledMessageDispatcher::class)) {
                    $container->factory(
                        ScheduledMessageDispatcher::class,
                        static fn() => $runtime->application()->make(ScheduledMessageDispatcher::class),
                    )->singleton();
                }
            });

        foreach (self::SYSTEM_COMMAND_GROUPS as $group => $systemCommands) {
            $builder->commandGroup('System/' . $group, ...array_keys($systemCommands));
        }

        if ($commandManifest !== null && is_file($commandManifest)) {
            $builder->commandManifest($commandManifest);
        } else {
            $builder->commands(self::commands($commands));
        }

        return $builder->build();
    }

    /** @return array<string, class-string<CommandContract>> */
    private static function systemCommands(): array
    {
        $commands = [];
        foreach (self::SYSTEM_COMMAND_GROUPS as $groupCommands) {
            $commands += $groupCommands;
        }

        return $commands;
    }
}
