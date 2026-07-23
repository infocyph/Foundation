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
use Infocyph\Foundation\Console\Command\ConfigCacheCommand;
use Infocyph\Foundation\Console\Command\ConfigClearCommand;
use Infocyph\Foundation\Console\Command\RouteCacheCommand;
use Infocyph\Foundation\Console\Command\RouteClearCommand;

final class FoundationConsole
{
    /** @var array<string, class-string<CommandContract>> */
    private const array SYSTEM_COMMANDS = [
        'app:ready' => AppReadyCommand::class,
        'auth:schema:status' => AuthSchemaStatusCommand::class,
        'auth:schema:install' => AuthSchemaInstallCommand::class,
        'config:cache' => ConfigCacheCommand::class,
        'config:clear' => ConfigClearCommand::class,
        'route:cache' => RouteCacheCommand::class,
        'route:clear' => RouteClearCommand::class,
    ];

    private function __construct() {}

    /**
     * @param Closure(?string): Application $applicationFactory
     * @param array<array-key, mixed> $commands
     */
    public static function create(
        Closure $applicationFactory,
        string $name = 'foundation',
        string $version = 'dev',
        array $commands = [],
    ): ConsoleApplication {
        $runtime = new FoundationConsoleRuntime($applicationFactory);

        return ConsoleApplication::configure()
            ->name($name)
            ->version($version)
            ->commands(self::commands($commands))
            ->containerProvider($runtime)
            ->configurationProvider($runtime)
            ->build();
    }

    /**
     * @param array<array-key, mixed> $applicationCommands
     * @return array<string, class-string<CommandContract>>
     */
    private static function commands(array $applicationCommands): array
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
}
